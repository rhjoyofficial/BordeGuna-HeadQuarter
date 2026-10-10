<?php

namespace App\Outcomes;

use App\Models\User;

/** Who wrote a ledger row. Not part of a row's meaning, so a retry by another worker is still a replay. */
final readonly class OutcomeActor
{
    public function __construct(
        public string $type,
        public ?int $id = null,
        public ?string $label = null,
    ) {}

    public static function system(?string $label = null): self
    {
        return new self('system', null, $label ?? 'system');
    }

    public static function human(User $user): self
    {
        return new self('human', (int) $user->getKey(), (string) $user->email);
    }

    public static function agent(string $agentKey): self
    {
        return new self('agent', null, $agentKey);
    }

    public function isHuman(): bool
    {
        return $this->type === 'human' && $this->id !== null;
    }
}
