<?php

namespace App\Autonomy\Ladder;

use App\Models\AutonomyGrant;
use App\Support\Audit\Auditor;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Writes the lapse of a ladder autonomy grant (docs/150, ChatGPT ruling 1 Nov 2026).
 *
 * `writeDurably()` is the normal path. It writes on a DEDICATED database connection (`autonomy_lapse`, a copy
 * of the default connection's settings with its own session), in its own short transaction, and commits
 * before it returns. A caller's transaction is a different session, so whatever the caller later does
 * (commit, roll back, throw, or the worker dying), the lapse and its audit row are already committed.
 *
 * `writeInCaller()` is the fallback for the two cases the dedicated session cannot handle: the grant row is
 * not visible to it (created in the caller's still-open transaction) or the write failed (lock timeout,
 * connection error). It writes on the caller's connection inside a savepoint, so it survives only if the
 * caller commits; `LadderGrantLapse` keeps a pending marker until then, and the sweep reconciles.
 *
 * Either path only ever moves an ACTIVE grant to `lapsed`; the database trigger forbids the way back.
 */
class DurableLapseWriter
{
    public const CONNECTION = 'autonomy_lapse';

    public const LAPSED = 'lapsed';

    public const NOT_ACTIVE = 'not_active';

    public const NOT_VISIBLE = 'not_visible';

    /** A caller holding the grant row locked must not stall a decision for long. */
    private const LOCK_TIMEOUT = '3s';

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @return string one of LAPSED (this call wrote it), NOT_ACTIVE (already lapsed or revoked) or NOT_VISIBLE
     *
     * @throws \Throwable when the dedicated session cannot write
     */
    public function writeDurably(AutonomyGrant $grant, string $code, string $reason): string
    {
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $grant, $code, $reason): string {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");
            }
            $row = $connection->table('autonomy_grants')->where('id', $grant->getKey())->lockForUpdate()->first();
            if ($row === null) {
                return self::NOT_VISIBLE;
            }
            if ($row->status !== 'active') {
                $grant->setRawAttributes((array) $row, true);

                return self::NOT_ACTIVE;
            }

            $values = ['status' => 'lapsed', 'lapsed_at' => now(), 'lapse_code' => $code, 'lapse_reason' => mb_substr($reason, 0, 2000), 'updated_at' => now()];
            $connection->table('autonomy_grants')->where('id', $grant->getKey())->where('status', 'active')->update($values);
            $grant->setRawAttributes((array) $connection->table('autonomy_grants')->where('id', $grant->getKey())->first(), true);
            $this->audit($grant, $code, $reason, self::CONNECTION);

            return self::LAPSED;
        });
    }

    /** The caller's own connection, in a savepoint when inside a transaction. Returns whether it lapsed it. */
    public function writeInCaller(AutonomyGrant $grant, string $code, string $reason): bool
    {
        return DB::transaction(function () use ($grant, $code, $reason): bool {
            $locked = AutonomyGrant::query()->whereKey($grant->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'active') {
                return false;
            }
            $locked->forceFill(['status' => 'lapsed', 'lapsed_at' => now(), 'lapse_code' => $code, 'lapse_reason' => mb_substr($reason, 0, 2000)])->save();
            $grant->setRawAttributes($locked->getAttributes(), true);
            $this->audit($locked, $code, $reason, null);

            return true;
        });
    }

    private function audit(AutonomyGrant $grant, string $code, string $reason, ?string $connection): void
    {
        $this->auditor->recordSystem('autonomy.grant.lapsed', $grant,
            before: ['status' => 'active'],
            after: ['status' => 'lapsed', 'grant' => $grant->uuid, 'business_id' => $grant->business_id, 'tool_key' => $grant->tool_key, 'lapse_code' => $code,
                'reactivates' => false, 'durable' => $connection !== null],
            reason: $reason,
            connection: $connection);
    }

    /**
     * The dedicated session: the default connection's settings, read at call time (so a parallel test
     * process's own database is used), registered under its own name with its own PDO.
     */
    private function connection(): Connection
    {
        $settings = config('database.connections.'.config('database.default'));
        if (config('database.connections.'.self::CONNECTION) !== $settings) {
            config(['database.connections.'.self::CONNECTION => $settings]);
            DB::purge(self::CONNECTION);
        }

        return DB::connection(self::CONNECTION);
    }
}
