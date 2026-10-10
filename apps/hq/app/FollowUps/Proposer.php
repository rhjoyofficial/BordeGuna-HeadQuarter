<?php

namespace App\FollowUps;

use App\Models\AgentRun;
use App\Models\User;
use InvalidArgumentException;

/**
 * Who is proposing (docs/113 section 4). A person may propose and, with the deciding permission,
 * decide. An agent run may ONLY propose: every deciding method of the service refuses it, and no
 * gateway tool exposes proposing to an agent today (docs/113 section 12).
 */
final readonly class Proposer
{
    public const PERSON = 'person';

    public const AGENT_RUN = 'agent_run';

    private function __construct(
        public string $type,
        public ?User $user = null,
        public ?AgentRun $run = null,
        public ?string $proposalRef = null,
    ) {}

    public static function person(User $user): self
    {
        return new self(self::PERSON, user: $user);
    }

    public static function agentRun(AgentRun $run, ?string $proposalRef = null): self
    {
        if ($proposalRef !== null && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $proposalRef) !== 1) {
            throw new InvalidArgumentException('A proposal ref is 1 to 64 of A-Z a-z 0-9 _ . : -');
        }

        return new self(self::AGENT_RUN, run: $run, proposalRef: $proposalRef);
    }

    public function isPerson(): bool
    {
        return $this->type === self::PERSON;
    }

    /** @return array<string, mixed> the proposer columns of either table */
    public function columns(): array
    {
        return [
            'proposer_type' => $this->type,
            'proposed_by_user_id' => $this->user?->getKey(),
            'proposed_by_agent_run_id' => $this->run?->getKey(),
            'proposal_ref' => $this->proposalRef,
        ];
    }
}
