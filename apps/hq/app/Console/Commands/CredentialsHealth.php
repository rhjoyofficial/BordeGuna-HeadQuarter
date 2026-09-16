<?php

namespace App\Console\Commands;

use App\Gateway\Readiness\CapabilityReadiness;
use App\Gateway\Readiness\ReadinessRow;
use Illuminate\Console\Command;

/**
 * The readiness table an operator reads before trusting a capability, and the gate runner prints
 * into its log before a run (ADR-043, docs/74 C.3). Never a value: provider, account, whether a
 * credential exists, its status, when it was last verified and with what result, whether HQ would
 * attempt a call and whether the provider last accepted the credential, as two columns (docs/77 E.1).
 * `--verify` runs the existing read-only probes and records what the provider said; `--verify-model`
 * also sends the model provider its 8-token check, which costs a fraction of a cent.
 *
 * Exit code: non-zero when a capability HQ supports cannot execute (an incident); zero when the
 * only gaps are unsupported by design. That is the check an activation runbook must pass.
 */
final class CredentialsHealth extends Command
{
    protected $signature = 'hq:credentials:health
        {--verify : run the read-only probes and record the result on each credential}
        {--verify-model : also send the model provider its 8-token check and record the answer (costs a fraction of a cent)}
        {--json : print the rows as JSON instead of a table}';

    protected $description = 'Capability and credential readiness per store, ERP business and model provider (no secrets).';

    public function handle(CapabilityReadiness $readiness): int
    {
        $rows = $readiness->inventory();

        if ($this->option('verify')) {
            $rows = array_map(fn (ReadinessRow $row) => $readiness->verify($row), $rows);
        }
        if ($this->option('verify-model')) {
            $rows = array_map(fn (ReadinessRow $row) => $readiness->verifyModel($row), $rows);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (ReadinessRow $r) => $r->toArray(), $rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['target', 'provider', 'state', 'credential', 'last checked', 'last result', 'would attempt', 'verified', 'why'],
                array_map(fn (ReadinessRow $r) => [
                    "{$r->targetKind}:{$r->target}",
                    $r->provider,
                    $r->state,
                    $r->credentialId === null ? 'none' : "#{$r->credentialId} {$r->credentialStatus}".($r->hasPayload ? '' : ' (no value)'),
                    $r->lastVerifiedAt ?? 'never',
                    $r->lastResult === null ? '-' : (($r->lastResult['ok'] ? 'ok' : (string) $r->lastResult['class']).' via '.$r->lastResult['source']),
                    $r->wouldAttempt() ? 'yes' : 'no',
                    $r->verified() ? 'yes' : 'no',
                    mb_substr($r->reason, 0, 90),
                ], $rows),
            );
        }

        $incidents = array_values(array_filter($rows, fn (ReadinessRow $r) => $r->incident()));
        $unsupported = count(array_filter($rows, fn (ReadinessRow $r) => ! $r->supported));

        $this->line(sprintf('%d rows: %d verified, %d would be attempted, %d unsupported by design, %d incident%s.',
            count($rows), count(array_filter($rows, fn (ReadinessRow $r) => $r->verified())),
            count(array_filter($rows, fn (ReadinessRow $r) => $r->wouldAttempt())), $unsupported, count($incidents), count($incidents) === 1 ? '' : 's'));

        foreach ($incidents as $row) {
            $this->error("  incident: {$row->targetKind}:{$row->target} {$row->provider} is {$row->state}: {$row->reason}");
        }

        return $incidents === [] ? self::SUCCESS : self::FAILURE;
    }
}
