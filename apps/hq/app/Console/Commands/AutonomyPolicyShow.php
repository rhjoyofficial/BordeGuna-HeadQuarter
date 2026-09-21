<?php

namespace App\Console\Commands;

use App\Autonomy\Evidence\EvidencePolicies;
use App\Models\AutonomyEvidencePolicy;
use Illuminate\Console\Command;

/**
 * Show the autonomy evidence policy (ADR-048): the active version or the statement that none is, and
 * every other version with its status and what is still undecided in it. Reads only.
 */
class AutonomyPolicyShow extends Command
{
    protected $signature = 'hq:autonomy-policy:show {version? : one version in full}';

    protected $description = 'Show the autonomy evidence policy versions (reads only).';

    public function handle(EvidencePolicies $policies): int
    {
        $active = $policies->active();
        $this->line($active === null
            ? 'No autonomy evidence policy is active, so evidence cannot widen autonomy.'
            : "Version {$active->version} ({$active->name}) is active, digest {$active->content_digest}.");

        $versions = AutonomyEvidencePolicy::query()
            ->when($this->argument('version') !== null, fn ($q) => $q->where('version', (int) $this->argument('version')))
            ->with(['requirements', 'hardChecks'])
            ->orderBy('version')
            ->get();

        foreach ($versions as $policy) {
            $state = match ($policy->status) {
                AutonomyEvidencePolicy::ACTIVE => 'ACTIVE',
                AutonomyEvidencePolicy::PROPOSED => 'proposed, not in force',
                default => 'retired, not in force',
            };
            $this->line('');
            $this->line("Version {$policy->version}: {$policy->name} ({$state})");

            foreach ($policies->snapshot($policy)['requirements'] as $r) {
                $this->line(sprintf('  %s %-14s rate %s, at least %s result(s), no older than %s day(s)', strtoupper($r['level']), $r['dimension'],
                    $r['min_agreement_rate'] === null ? 'NOT DECIDED' : number_format($r['min_agreement_rate'] / 1000, 3),
                    $r['min_sample_count'] ?? 'NOT DECIDED', $r['max_evidence_age_days'] ?? 'NOT DECIDED'));
            }
            foreach ($policies->snapshot($policy)['hard_checks'] as $c) {
                $this->line(sprintf('  %s hard check %s: 100 percent, at least %s pass(es), no older than %s day(s)', strtoupper($c['level']), $c['check'],
                    $c['min_sample_count'] ?? 'NOT DECIDED', $c['max_evidence_age_days'] ?? 'NOT DECIDED'));
            }
            if ($policy->status === AutonomyEvidencePolicy::PROPOSED) {
                foreach ($policies->structuralRefusals($policy) as $refusal) {
                    $this->line("  blocks activation: {$refusal}");
                }
            }
        }

        return self::SUCCESS;
    }
}
