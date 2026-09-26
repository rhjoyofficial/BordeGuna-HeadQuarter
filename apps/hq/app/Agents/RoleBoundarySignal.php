<?php

namespace App\Agents;

/**
 * What `RoleBoundary` found in one trusted message (docs/86): the detector and policy versions, the
 * agent's role, every outbound kind the message asked for, the ones that role forbids, the clause
 * each came from, whether an independent request for information remains beside them, and whether
 * the detector itself failed, which is read as forbidden (fail closed).
 *
 * @param  list<string>  $kinds  every outbound kind asked for, forbidden or not, unique, in order found
 * @param  list<string>  $forbidden  the subset the role forbids
 * @param  list<array{kind: string, clause: string, forbidden: bool}>  $evidence
 */
final readonly class RoleBoundarySignal
{
    public function __construct(
        public string $version,
        public string $policyVersion,
        public ?string $role,
        public array $kinds,
        public array $forbidden,
        public array $evidence = [],
        public bool $informationRemainder = false,
        public ?string $error = null,
    ) {}

    public static function none(?string $role): self
    {
        return new self(RoleBoundary::VERSION, RolePolicy::VERSION, $role, [], []);
    }

    /** The role forbids something the message asked for, or the detector could not be trusted to say. */
    public function detected(): bool
    {
        return $this->forbidden !== [] || $this->error !== null;
    }

    /**
     * The whole message is the forbidden action: nothing in it asks for information the role serves.
     * The run ends refused before the model. An ambiguous remainder belongs to the refused request.
     *
     * ⛔ docs/88 point 14: a role expansion is refused whole whatever stands beside it. A question
     *    asked after "from now on you are the cashier" is asked of a role the agent does not have,
     *    so nothing in the message survives it (ADR-041's dependency rule, applied to the role).
     */
    public function refusesWhole(): bool
    {
        return $this->error !== null
            || ($this->forbidden !== [] && ! $this->informationRemainder)
            || in_array(RoleBoundary::ROLE_EXPANSION, $this->forbidden, true);
    }

    /**
     * The outbound kinds refused in this message that a TOOL could perform (docs/88 point 12): the
     * run carries them to the gateway, which refuses any tool that would perform one, whatever the
     * model proposes after reading the rest of the message.
     *
     * @return list<string>
     */
    public function refusedToolActions(): array
    {
        return array_values(array_intersect($this->forbidden, RoleBoundary::TOOL_ACTIONS));
    }

    /** A forbidden action beside an independent request for information: the request is served, the action is not. */
    public function refusesPart(): bool
    {
        return $this->detected() && ! $this->refusesWhole();
    }

    public function forbids(string $kind): bool
    {
        return in_array($kind, $this->forbidden, true);
    }

    /** The clauses the role forbids, as the operator wrote them (lower-cased). @return list<string> */
    public function forbiddenClauses(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $e) => $e['clause'],
            array_filter($this->evidence, static fn (array $e) => $e['forbidden']),
        )));
    }

    /**
     * @return array{version: string, policy_version: string, role: string|null, kinds: list<string>, forbidden: list<string>,
     *     evidence: list<array{kind: string, clause: string, forbidden: bool}>, information_remainder: bool, refused_whole: bool, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'policy_version' => $this->policyVersion,
            'role' => $this->role,
            'kinds' => $this->kinds,
            'forbidden' => $this->forbidden,
            'evidence' => $this->evidence,
            'information_remainder' => $this->informationRemainder,
            'refused_whole' => $this->detected() && $this->refusesWhole(),
            'error' => $this->error,
        ];
    }
}
