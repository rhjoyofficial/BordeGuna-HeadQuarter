<?php

namespace App\Skills;

use App\Models\Business;
use App\Models\Skill;
use App\Models\User;
use App\Research\ResearchRefused;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The skill registry (docs/10, docs/89 Phase 9, docs/145-c).
 *
 * A skill is a named procedure limited to the tools it lists. Its life:
 *
 *   proposed  (a person, or an agent's proposal recorded by a person's tool; never self-activating)
 *   approved  (a person with hq.skills.activate read it)
 *   active    (a person with hq.skills.activate switched it on; one active version per key)
 *   suspended (anyone who may manage skills, at once: the safe direction)
 *   retired
 *
 * A skill grants NOTHING. Its tool list is only ever used to narrow a delegation's tools
 * (`ExecutionContextResolver::resolveDelegated`); every call still goes through the gateway with its own
 * permission, policy, approval and consent. A skill may not list a permanently human-only tool, and a tool
 * that is not registered is refused at proposal time.
 */
final class SkillRegistry
{
    public const STATUSES = ['proposed', 'approved', 'active', 'suspended', 'retired'];

    public function __construct(
        private readonly BusinessScope $scope,
        private readonly Auditor $auditor,
        private readonly ToolRegistry $tools,
    ) {}

    /** @param  array{key: string, name: string, purpose: string, required_tools: list<string>}  $data */
    public function propose(User $by, ?Business $business, array $data, string $proposerType = 'person', ?string $proposalRef = null): Skill
    {
        if ($business === null ? $this->scope->isRestricted($by) : ! $this->scope->allows($by, (int) $business->getKey())) {
            throw new ResearchRefused($business === null ? 'A group-wide skill needs group-wide authority.' : 'That business is outside what you may act on.');
        }
        if (! in_array($proposerType, ['person', 'agent'], true)) {
            throw new ResearchRefused('A skill is proposed by a person or recorded from an agent\'s proposal.');
        }

        $key = trim((string) ($data['key'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $key) !== 1) {
            throw new ResearchRefused('A skill key is lower-case letters, digits and _, starting with a letter (3 to 64 characters).');
        }
        $name = trim((string) ($data['name'] ?? ''));
        $purpose = trim((string) ($data['purpose'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120 || $purpose === '' || mb_strlen($purpose) > 2000) {
            throw new ResearchRefused('A skill has a name (at most 120 characters) and a purpose (at most 2,000).');
        }
        $tools = $this->checkTools((array) ($data['required_tools'] ?? []));

        return DB::transaction(function () use ($by, $business, $key, $name, $purpose, $tools, $proposerType, $proposalRef): Skill {
            $version = (int) Skill::query()->where('key', $key)->lockForUpdate()->pluck('version')->max() + 1;
            $skill = Skill::query()->create([
                'key' => $key,
                'version' => $version,
                'name' => $name,
                'purpose' => $purpose,
                'required_tools' => $tools,
                'business_id' => $business?->getKey(),
                'proposer_type' => $proposerType,
                'proposal_ref' => $proposalRef !== null ? mb_substr($proposalRef, 0, 120) : null,
                'owner_user_id' => $by->getKey(),
                'status' => 'proposed',
                'created_by' => $by->getKey(),
            ]);
            $this->auditor->record('skills.proposed', $skill, after: ['key' => $key, 'version' => $version, 'tools' => $tools, 'business_id' => $business?->getKey(), 'proposer_type' => $proposerType],
                actorUserId: $by->getKey());

            return $skill;
        });
    }

    public function approve(User $by, Skill $skill, string $note): Skill
    {
        return $this->move($by, $skill, ['proposed'], 'approved', $note, ['approved_by' => $by->getKey(), 'approved_at' => now()]);
    }

    public function activate(User $by, Skill $skill, string $note): Skill
    {
        // The tools are read again: a tool unregistered or made human-only since approval stops activation.
        $this->checkTools((array) $skill->required_tools);

        return DB::transaction(function () use ($by, $skill, $note): Skill {
            Skill::query()->where('key', $skill->key)->where('status', 'active')->whereKeyNot($skill->getKey())->lockForUpdate()->get()
                ->each(fn (Skill $old) => $this->move($by, $old, ['active'], 'retired', "superseded by version {$skill->version}", ['retired_by' => $by->getKey(), 'retired_at' => now()]));

            return $this->move($by, $skill, ['approved', 'suspended'], 'active', $note, ['activated_by' => $by->getKey(), 'activated_at' => now()]);
        });
    }

    /** The safe direction: always allowed from active, takes effect at once. */
    public function suspend(User $by, Skill $skill, string $reason): Skill
    {
        return $this->move($by, $skill, ['active', 'approved'], 'suspended', $reason);
    }

    public function retire(User $by, Skill $skill, string $reason): Skill
    {
        return $this->move($by, $skill, ['proposed', 'approved', 'active', 'suspended'], 'retired', $reason, ['retired_by' => $by->getKey(), 'retired_at' => now()]);
    }

    /** @param  list<string>  $from */
    private function move(User $by, Skill $skill, array $from, string $to, string $reason, array $extra = []): Skill
    {
        if ($skill->business_id === null ? $this->scope->isRestricted($by) : ! $this->scope->allows($by, (int) $skill->business_id)) {
            throw new ResearchRefused('That skill is outside what you may act on.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ResearchRefused('Say why.');
        }

        return DB::transaction(function () use ($by, $skill, $from, $to, $reason, $extra): Skill {
            $locked = Skill::query()->whereKey($skill->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, $from, true)) {
                throw new ResearchRefused("A skill that is {$locked->status} cannot become {$to}.");
            }
            $before = $locked->status;
            $locked->forceFill(['status' => $to, 'status_reason' => mb_substr($reason, 0, 2000)] + $extra)->save();
            $this->auditor->record("skills.{$to}", $locked, before: ['status' => $before], after: ['status' => $to, 'key' => $locked->key, 'version' => $locked->version,
                'self_decided' => (int) $locked->created_by === (int) $by->getKey()], reason: $reason, actorUserId: $by->getKey());

            return $locked;
        });
    }

    /** @return list<string> */
    private function checkTools(array $tools): array
    {
        $tools = array_values(array_unique(array_map(static fn ($t): string => trim((string) $t), $tools)));
        if ($tools === [] || count($tools) > 20) {
            throw new ResearchRefused('A skill lists between 1 and 20 tools: the only ones it may ever call.');
        }
        foreach ($tools as $key) {
            if (! $this->tools->has($key)) {
                throw new ResearchRefused("[{$key}] is not a registered tool, so no skill can use it.");
            }
            try {
                $descriptor = $this->tools->get($key)->descriptor();
            } catch (Throwable) {
                throw new ResearchRefused("[{$key}] cannot be read.");
            }
            if ($descriptor->permanentlyHumanOnly) {
                throw new ResearchRefused("[{$key}] is permanently human-only; no skill may list it.");
            }
        }
        sort($tools);

        return $tools;
    }
}
