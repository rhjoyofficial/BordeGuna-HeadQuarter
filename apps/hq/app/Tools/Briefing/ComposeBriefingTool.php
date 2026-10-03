<?php

namespace App\Tools\Briefing;

use App\Briefing\BriefingComposer;
use App\Briefing\ExecutiveBriefingContract;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Briefing;
use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * `briefing.compose` (docs/48 M.4, docs/80 C.1): collects the Executive Briefing's evidence bundle,
 * validates it and stores the briefing so it can be read again.
 *
 * **Tier R3, and honest about it.** Everything it reads is HQ's own read models (R1), and it touches
 * nothing outside HQ. But it writes a `briefings` row, which is HQ state and not merely the record of
 * the call, and the rule is the highest tier of any effect (`RiskTier`; `erp.poll_*` is R3 for the
 * same reason). R3 is still automatic: no approval, no external effect, invisible outside HQ.
 *
 * **Not agent-executable.** A briefing is composed by the scheduler or a person. An agent composing
 * its own evidence surface is a loop nobody has asked for, and "not yet" is the conservative answer.
 *
 * **Permissions are an intersection inside the tool too.** The tool itself needs
 * `hq.tools.facts.read`. The approvals and the scheduler sections read what `hq.list_approvals` and
 * `hq.list_jobs` guard with their own permissions, so they are read only when the caller holds those
 * as well, and are otherwise stored as `withheld`, which degrades the briefing and says why. An
 * ownerless system call holds only the fixed `hq.gateway.system_permissions` list.
 *
 * ⛔ No seeder entry and no cron. The standard morning job (docs/95 §16 NOW item 4) is one command a
 * person runs, `hq:scheduler:ensure "Daily owner briefing" --tool=briefing.compose --cron="0 9 * * *"
 * --created-from=ui --owner=<owner email> --apply`; because this tool is R3 the job is created waiting
 * for approval and nothing runs it until a person approves it on the scheduler. With `ai.runtime` off
 * and `briefing.narration` absent, which is production today, each run composes the evidence bundle
 * only (`narration_state = off`): the composer resolves no narrator and no model can be reached.
 */
final class ComposeBriefingTool implements Tool
{
    /** Section => the permission its own read tool already requires. */
    private const SECTION_PERMISSIONS = ['approvals' => 'hq.approvals.view', 'jobs' => 'hq.jobs.view'];

    public function __construct(
        private readonly ExecutiveBriefingContract $contract,
        private readonly BriefingComposer $composer,
    ) {}

    public function key(): string
    {
        return ExecutiveBriefingContract::TOOL;
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Compose the executive briefing',
            purpose: 'Collects the typed evidence bundle of the Executive standard briefing from HQ read models, validates it and stores the briefing. Calls no model while narration is off.',
            riskTier: RiskTier::R3,
            permission: 'hq.tools.facts.read',
            auditAction: 'tool.briefing_compose',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            idempotent: true,
            idempotencyKeyFrom: ['business_slug', 'date'],
            timeoutSeconds: 60,
            outputSchema: $this->outputSchema(),
        );
    }

    public function inputSchema(): array
    {
        return $this->contract->inputSchema();
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'briefing_uuid' => ['type' => 'string'],
            'briefing_date' => ['type' => 'string'],
            'state' => ['type' => 'string'],
            'degraded_reasons' => ['type' => 'array'],
            'narration_state' => ['type' => 'string'],
            'items' => ['type' => 'integer'],
            'stale_sources' => ['type' => 'array'],
            'missing_sources' => ['type' => 'array'],
        ]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        try {
            $request = $this->contract->request($payload, CarbonImmutable::now('UTC'), $context->permittedBusinessIds, $this->mayRead($context));
        } catch (InvalidArgumentException $e) {
            return ToolResult::failed($e->getMessage());
        }

        $briefing = $this->composer->compose($request, $context->idempotencyKey, $context->correlationId, $context->actorUserId);

        return ToolResult::succeeded($this->summary($briefing));
    }

    /** The briefing is its own record: a retry with the same key finds the row the first attempt wrote. */
    public function reconcile(ToolContext $context): ?ToolResult
    {
        $briefing = Briefing::query()->where('idempotency_key', $context->idempotencyKey)->first();

        return $briefing === null ? null : ToolResult::succeeded($this->summary($briefing));
    }

    /** @return array<string, bool> */
    private function mayRead(ToolContext $context): array
    {
        $user = $context->actorUserId === null ? null : User::find($context->actorUserId);
        $system = (array) config('hq.gateway.system_permissions', []);

        return array_map(
            static fn (string $permission): bool => $user !== null ? (bool) $user->can($permission) : in_array($permission, $system, true),
            self::SECTION_PERMISSIONS,
        );
    }

    /** The verdict, not the bundle: the bundle lives once, on the briefing row. */
    private function summary(Briefing $briefing): array
    {
        return [
            'briefing_uuid' => $briefing->uuid,
            'briefing_date' => $briefing->briefing_date->toDateString(),
            'state' => $briefing->state,
            'degraded_reasons' => array_map(static fn (array $r): array => ['code' => $r['code'], 'subject' => $r['subject'] ?? null], (array) $briefing->degraded_reasons),
            'narration_state' => $briefing->narration_state,
            'items' => count((array) ($briefing->bundle['items'] ?? [])),
            'stale_sources' => (array) ($briefing->bundle['freshness']['stale'] ?? []),
            'missing_sources' => (array) ($briefing->bundle['freshness']['missing'] ?? []),
        ];
    }
}
