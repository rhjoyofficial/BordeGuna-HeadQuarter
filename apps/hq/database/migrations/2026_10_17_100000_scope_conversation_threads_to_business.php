<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/140 (ruling docs/138 section 5 P3-W1, docs/131 ruling 2): a platform thread reference is unique per
 * BUSINESS, not across the group.
 *
 * Before: UNIQUE (channel, external_thread_ref) over open threads, so the same reference arriving for two
 * businesses could only be refused (ConversationService fails closed on it). After: UNIQUE (business_id, channel,
 * external_thread_ref) over open threads, so each business holds its own thread and neither ever sees the other's.
 *
 * Additive in effect: the new key is created first, then the old one is dropped, so there is no moment without a
 * key. The new key is strictly weaker than the old one, but the check below still reads the rows first and refuses,
 * naming what it found, when an open thread would violate it (a database where the old index was never built).
 *
 * Down: puts the global key back only when no open thread reference is held by two businesses; otherwise it refuses
 * and the way back is the pre-deploy backup (two real threads are never merged or closed to make a rollback fit).
 */
return new class extends Migration
{
    private const OPEN = "external_thread_ref IS NOT NULL AND status <> 'closed'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('business-scoped conversation threads need PostgreSQL (partial unique index).');
        }

        $clash = DB::select('SELECT business_id, channel, external_thread_ref, count(*) AS n FROM conversations WHERE '.self::OPEN
            .' GROUP BY business_id, channel, external_thread_ref HAVING count(*) > 1 ORDER BY business_id, channel, external_thread_ref LIMIT 20');
        if ($clash !== []) {
            $names = implode('; ', array_map(fn ($r) => "business {$r->business_id} {$r->channel} [{$r->external_thread_ref}] x{$r->n}", $clash));
            throw new RuntimeException('Refusing to scope conversation threads to their business: '.count($clash).' open thread reference(s) are held '
                ."more than once in one business ({$names}). Close or repair the duplicates by hand with evidence first; nothing was changed.");
        }

        DB::unprepared(
            'CREATE UNIQUE INDEX conversations_business_channel_thread_unique ON conversations (business_id, channel, external_thread_ref) WHERE '.self::OPEN.';'
            .' DROP INDEX IF EXISTS conversations_channel_thread_unique;'
        );
    }

    public function down(): void
    {
        $shared = (int) DB::selectOne('SELECT count(*) AS n FROM (SELECT 1 FROM conversations WHERE '.self::OPEN
            .' GROUP BY channel, external_thread_ref HAVING count(DISTINCT business_id) > 1 OR count(*) > 1) d')->n;
        if ($shared > 0) {
            throw new RuntimeException("Refusing to roll back: {$shared} open thread reference(s) are now held by more than one business, which the old "
                .'group-wide key cannot hold. Restore the pre-deploy backup instead (docs/140); no thread is closed or merged to make a rollback fit.');
        }

        DB::unprepared(
            'CREATE UNIQUE INDEX conversations_channel_thread_unique ON conversations (channel, external_thread_ref) WHERE '.self::OPEN.';'
            .' DROP INDEX IF EXISTS conversations_business_channel_thread_unique;'
        );
    }
};
