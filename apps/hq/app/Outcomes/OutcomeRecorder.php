<?php

namespace App\Outcomes;

use App\Context\Provenance;
use App\Gateway\Approval\CanonicalPayload;
use App\Models\Brand;
use App\Models\Outcome;
use App\Models\OutcomeAttribution;
use App\Models\OutcomeResultRef;
use App\Models\Store;
use App\Support\Audit\Auditor;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only writer of the outcome ledgers (docs/107 section 6).
 *
 * ⭐ Idempotent on the caller's key: a second `record()` with the same key and the same meaning returns
 *    the existing row (`wasRecentlyCreated` false); the same key with a different meaning throws
 *    `OutcomeConflict`. Nothing is ever overwritten: a changed belief is `supersede()` or `retract()`,
 *    and the unique index on the superseded id makes a second concurrent correction fail rather than
 *    fork the history.
 *
 * ⛔ Fail closed: an unregistered type, a source or provenance the type does not allow, a missing
 *    subject, a reference or action in another business, an action with no business of its own
 *    (`ImplicitHqScopeRefused`, ruling Q6), a method the running policy does not admit
 *    (`AttributionMethodInactive`, ruling Q3), an impossible confidence for the method, or
 *    oversized evidence writes nothing (`InvalidArgumentException`).
 *
 * Writers: the recorders under `App\Outcomes\Recorders` and the one gateway tool `outcomes.attribute`
 * (docs/115 §4); `OutcomeWritePathStructureTest` holds that nothing else reaches this class or the tables.
 *
 * ⛔ Recording grants nothing (D13). No permission, approval, gate or autonomy level reads these rows.
 *
 * Inert in this release: nothing in a live path calls this class.
 */
final class OutcomeRecorder
{
    /** The systems a natural key may name. */
    public const SOURCE_SYSTEMS = ['erp', 'shop', 'hq', 'meta', 'courier'];

    /** Evidence is identifiers and digests (D5): bounded so it cannot become a data store. */
    public const MAX_EVIDENCE_BYTES = 16384;

    public function __construct(private readonly Auditor $auditor) {}

    public function record(OutcomeSpec $spec): Outcome
    {
        return $this->write($spec, null, OutcomeState::Asserted, null);
    }

    /** A corrected version of `$prior`: same business and type, a new meaning, a reason. */
    public function supersede(Outcome $prior, OutcomeSpec $spec, string $reason, OutcomeActor $actor): Outcome
    {
        if ($spec->businessId !== (int) $prior->business_id || $spec->type !== $prior->outcome_type) {
            throw new InvalidArgumentException('A correction keeps the business and the type of the outcome it corrects; anything else is a retraction and a new outcome.');
        }
        $this->requireReason($reason);
        $this->requireAsserted($prior);
        $outcome = $this->write($this->withActor($spec, $actor), $prior, OutcomeState::Asserted, $reason);
        if ($outcome->wasRecentlyCreated) {
            $this->audit('outcome.superseded', $outcome, $prior, $reason, $actor);
        }

        return $outcome;
    }

    /** Withdraws `$prior`: a successor row in state `retracted`, carrying the prior's references. */
    public function retract(Outcome $prior, string $reason, OutcomeActor $actor): Outcome
    {
        $this->requireReason($reason);
        $this->requireAsserted($prior);
        $results = $prior->results()->orderBy('id')->get()->map(function (OutcomeResultRef $r) {
            $column = $r->result_type->column();
            $rowId = $column === null || $r->{$column} === null ? null : (int) $r->{$column};

            return new ResultRefSpec($r->result_type, $r->source_system, $r->external_ref, $rowId, $r->role);
        })->all();

        $spec = new OutcomeSpec(
            type: $prior->outcome_type,
            businessId: (int) $prior->business_id,
            occurredAt: CarbonImmutable::instance($prior->occurred_at),
            source: OutcomeSource::from($prior->source),
            sourceSystem: $prior->source_system,
            provenance: Provenance::from($prior->provenance),
            idempotencyKey: 'retract:'.$prior->idempotency_key,
            results: $results,
            brandId: $prior->brand_id,
            storeId: $prior->store_id,
            sourceAsOf: $prior->source_as_of ? CarbonImmutable::instance($prior->source_as_of) : null,
            sourceRef: $prior->source_ref,
            evidence: $prior->evidence,
            actor: $actor,
        );
        $outcome = $this->write($spec, $prior, OutcomeState::Retracted, $reason);
        if ($outcome->wasRecentlyCreated) {
            $this->audit('outcome.retracted', $outcome, $prior, $reason, $actor);
        }

        return $outcome;
    }

    public function attribute(Outcome $outcome, AttributionSpec $spec): OutcomeAttribution
    {
        return $this->writeAttribution($outcome, $spec, null, OutcomeState::Asserted);
    }

    /** A re-judged claim: same outcome and action, a new role, method or confidence, and a reason. */
    public function supersedeAttribution(OutcomeAttribution $prior, AttributionSpec $spec, string $reason, OutcomeActor $actor): OutcomeAttribution
    {
        if ($spec->actionType !== $prior->action_type || $spec->actionId !== $prior->actionId()) {
            throw new InvalidArgumentException('A re-judged attribution keeps its action; crediting a different action is a new attribution.');
        }
        $this->requireReason($reason);
        $this->requireAsserted($prior);
        $spec = new AttributionSpec($spec->actionType, $spec->actionId, $spec->actionRef, $spec->role, $spec->method, $spec->methodVersion,
            $spec->confidence, $spec->idempotencyKey, $spec->proposalRef, $spec->evidence, $actor, $spec->correlationId, $reason);
        $row = $this->writeAttribution($prior->outcome, $spec, $prior, OutcomeState::Asserted);
        if ($row->wasRecentlyCreated) {
            $this->audit('outcome_attribution.superseded', $row, $prior, $reason, $actor);
        }

        return $row;
    }

    public function retractAttribution(OutcomeAttribution $prior, string $reason, OutcomeActor $actor): OutcomeAttribution
    {
        $this->requireReason($reason);
        $this->requireAsserted($prior);
        $spec = new AttributionSpec($prior->action_type, $prior->actionId(), $prior->action_ref, $prior->role, $prior->method, $prior->method_version,
            (float) $prior->confidence, 'retract:'.$prior->idempotency_key, $prior->proposal_ref, $prior->evidence, $actor, null, $reason);
        $row = $this->writeAttribution($prior->outcome, $spec, $prior, OutcomeState::Retracted);
        if ($row->wasRecentlyCreated) {
            $this->audit('outcome_attribution.retracted', $row, $prior, $reason, $actor);
        }

        return $row;
    }

    private function write(OutcomeSpec $spec, ?Outcome $prior, OutcomeState $state, ?string $reason): Outcome
    {
        $definition = $this->validateOutcome($spec, $state);
        $occurredAt = $this->utc($spec->occurredAt);
        $sourceAsOf = $spec->sourceAsOf === null ? null : $this->utc($spec->sourceAsOf);
        $results = $spec->results;
        usort($results, fn (ResultRefSpec $a, ResultRefSpec $b) => [$a->sourceSystem, $a->externalRef] <=> [$b->sourceSystem, $b->externalRef]);

        $digest = CanonicalPayload::hash([
            'schema_version' => $definition->schemaVersion,
            'type' => $spec->type,
            'business_id' => $spec->businessId,
            'brand_id' => $spec->brandId,
            'store_id' => $spec->storeId,
            'state' => $state->value,
            'occurred_at' => $occurredAt->toIso8601ZuluString('microsecond'),
            'source_as_of' => $sourceAsOf?->toIso8601ZuluString('microsecond'),
            'source' => $spec->source->value,
            'source_system' => $spec->sourceSystem,
            'source_ref' => $spec->sourceRef,
            'provenance' => $spec->provenance->value,
            'evidence' => $spec->evidence,
            'results' => array_map(fn (ResultRefSpec $r) => $r->meaning(), $results),
            'supersedes' => $prior?->id,
            'reason' => $reason,
        ]);

        $existing = $this->existingOutcome($spec->idempotencyKey, $digest);
        if ($existing !== null) {
            return $existing;
        }

        $actor = $spec->actor ?? OutcomeActor::system();

        try {
            return DB::transaction(function () use ($spec, $definition, $state, $occurredAt, $sourceAsOf, $results, $digest, $prior, $reason, $actor) {
                $outcome = Outcome::create([
                    'uuid' => (string) Str::uuid(),
                    'schema_version' => $definition->schemaVersion,
                    'business_id' => $spec->businessId,
                    'brand_id' => $spec->brandId,
                    'store_id' => $spec->storeId,
                    'outcome_type' => $spec->type,
                    'state' => $state,
                    'occurred_at' => $occurredAt,
                    'source_as_of' => $sourceAsOf,
                    'recorded_at' => CarbonImmutable::now('UTC'),
                    'source' => $spec->source->value,
                    'source_system' => $spec->sourceSystem,
                    'source_ref' => $spec->sourceRef,
                    'provenance' => $spec->provenance->value,
                    'evidence' => $spec->evidence,
                    'content_digest' => $digest,
                    'actor_type' => $actor->type,
                    'actor_id' => $actor->id,
                    'actor_label' => $actor->label,
                    'correlation_id' => $spec->correlationId,
                    'idempotency_key' => $spec->idempotencyKey,
                    'supersedes_outcome_id' => $prior?->id,
                    'reason' => $reason,
                ]);

                foreach ($results as $ref) {
                    $row = [
                        'result_type' => $ref->type,
                        'role' => $ref->role,
                        'source_system' => $ref->sourceSystem,
                        'external_ref' => $ref->externalRef,
                    ];
                    if ($ref->type->column() !== null) {
                        $row[$ref->type->column()] = $ref->rowId;
                    }
                    $outcome->results()->create($row);
                }

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            // Someone else won: either the same observation under the same key (a replay, decided by
            // the digest), or a concurrent correction of the same prior row (a conflict).
            return $this->existingOutcome($spec->idempotencyKey, $digest)
                ?? throw new OutcomeConflict('That outcome was already corrected or retracted by another writer; re-read it and correct the current row.');
        }
    }

    private function writeAttribution(Outcome $outcome, AttributionSpec $spec, ?OutcomeAttribution $prior, OutcomeState $state): OutcomeAttribution
    {
        $this->validateAttribution($outcome, $spec);

        $digest = CanonicalPayload::hash([
            'schema_version' => OutcomeTypes::ATTRIBUTION_SCHEMA_VERSION,
            'outcome_id' => $outcome->id,
            'action_type' => $spec->actionType->value,
            'action_id' => $spec->actionId,
            'action_ref' => $spec->actionRef,
            'proposal_ref' => $spec->proposalRef,
            'role' => $spec->role->value,
            'method' => $spec->method->value,
            'method_version' => $spec->methodVersion,
            'confidence' => round($spec->confidence, 4),
            'state' => $state->value,
            'evidence' => $spec->evidence,
            'supersedes' => $prior?->id,
            'reason' => $spec->reason,
        ]);

        $existing = $this->existingAttribution($spec->idempotencyKey, $digest);
        if ($existing !== null) {
            return $existing;
        }

        $actor = $spec->actor ?? OutcomeActor::system();

        try {
            return DB::transaction(fn () => OutcomeAttribution::create([
                'uuid' => (string) Str::uuid(),
                'schema_version' => OutcomeTypes::ATTRIBUTION_SCHEMA_VERSION,
                'outcome_id' => $outcome->id,
                'business_id' => $outcome->business_id,
                'action_type' => $spec->actionType,
                $spec->actionType->column() => $spec->actionId,
                'action_ref' => $spec->actionRef,
                'proposal_ref' => $spec->proposalRef,
                'role' => $spec->role,
                'method' => $spec->method,
                'method_version' => $spec->methodVersion,
                'confidence' => round($spec->confidence, 4),
                'state' => $state,
                'evidence' => $spec->evidence,
                'content_digest' => $digest,
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'actor_label' => $actor->label,
                'correlation_id' => $spec->correlationId,
                'idempotency_key' => $spec->idempotencyKey,
                'supersedes_attribution_id' => $prior?->id,
                'reason' => $spec->reason,
                'recorded_at' => CarbonImmutable::now('UTC'),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->existingAttribution($spec->idempotencyKey, $digest)
                ?? throw new OutcomeConflict('That attribution was already re-judged or retracted by another writer; re-read it and correct the current row.');
        }
    }

    private function existingOutcome(string $key, string $digest): ?Outcome
    {
        $existing = Outcome::query()->where('idempotency_key', $key)->first();
        if ($existing === null) {
            return null;
        }
        if (! hash_equals($existing->content_digest, $digest)) {
            throw new OutcomeConflict("Outcome key [{$key}] is already recorded with a different meaning. The ledger never overwrites: supersede the recorded row if it is wrong.");
        }

        return $existing;
    }

    private function existingAttribution(string $key, string $digest): ?OutcomeAttribution
    {
        $existing = OutcomeAttribution::query()->where('idempotency_key', $key)->first();
        if ($existing === null) {
            return null;
        }
        if (! hash_equals($existing->content_digest, $digest)) {
            throw new OutcomeConflict("Attribution key [{$key}] is already recorded with a different meaning. Supersede the recorded row if it is wrong.");
        }

        return $existing;
    }

    private function validateOutcome(OutcomeSpec $spec, OutcomeState $state): OutcomeTypeDefinition
    {
        $definition = OutcomeTypes::get($spec->type);

        if (! $definition->allowsSource($spec->source)) {
            throw new InvalidArgumentException("[{$spec->type}] may not be learned from [{$spec->source->value}].");
        }
        if (! $definition->allowsProvenance($spec->provenance)) {
            throw new InvalidArgumentException("[{$spec->type}] may not carry provenance [{$spec->provenance->value}].");
        }
        $this->requireSourceSystem($spec->sourceSystem);
        $this->requireKey($spec->idempotencyKey);
        if ($spec->correlationId !== null && strlen($spec->correlationId) > 128) {
            throw new InvalidArgumentException('A correlation id is at most 128 characters.');
        }
        $this->requireEvidence($spec->evidence);

        if ($spec->brandId !== null && (int) Brand::query()->whereKey($spec->brandId)->value('business_id') !== $spec->businessId) {
            throw new InvalidArgumentException('The brand does not belong to the outcome\'s business.');
        }
        if ($spec->storeId !== null && (int) Store::query()->whereKey($spec->storeId)->value('business_id') !== $spec->businessId) {
            throw new InvalidArgumentException('The store does not belong to the outcome\'s business.');
        }

        $hasSubject = false;
        $seen = [];
        foreach ($spec->results as $ref) {
            if (! $ref instanceof ResultRefSpec) {
                throw new InvalidArgumentException('Every result reference is a ResultRefSpec.');
            }
            $this->validateResultRef($ref, $spec->businessId);
            $natural = $ref->sourceSystem."\0".$ref->externalRef;
            if (isset($seen[$natural])) {
                throw new InvalidArgumentException("The result [{$ref->sourceSystem}:{$ref->externalRef}] is referenced twice.");
            }
            $seen[$natural] = true;
            $hasSubject = $hasSubject || ($ref->role === ResultRefSpec::ROLE_SUBJECT && $definition->allowsSubject($ref->type));
        }
        if (! $hasSubject) {
            throw new InvalidArgumentException("[{$spec->type}] needs a subject reference of kind ".implode(' or ', array_map(fn (ResultType $t) => $t->value, $definition->subjects)).'.');
        }

        return $definition;
    }

    private function validateResultRef(ResultRefSpec $ref, int $businessId): void
    {
        if (! in_array($ref->role, [ResultRefSpec::ROLE_SUBJECT, ResultRefSpec::ROLE_CONTEXT], true)) {
            throw new InvalidArgumentException("[{$ref->role}] is not a result role.");
        }
        $this->requireSourceSystem($ref->sourceSystem);
        if (trim($ref->externalRef) === '' || strlen($ref->externalRef) > 191) {
            throw new InvalidArgumentException('A result reference needs the system of record\'s own key (1 to 191 characters).');
        }
        if ($ref->type === ResultType::External) {
            if ($ref->rowId !== null) {
                throw new InvalidArgumentException('An external reference points at no HQ row.');
            }

            return;
        }
        if ($ref->rowId === null) {
            if ($ref->type->requiresRow()) {
                throw new InvalidArgumentException("A [{$ref->type->value}] reference must point at its HQ row.");
            }

            return;
        }

        $owner = $this->resultBusiness($ref->type, $ref->rowId);
        if ($owner === false) {
            throw new InvalidArgumentException("The [{$ref->type->value}] row {$ref->rowId} does not exist.");
        }
        if ($owner !== $businessId) {
            throw new InvalidArgumentException("The [{$ref->type->value}] row {$ref->rowId} belongs to another business.");
        }
    }

    private function validateAttribution(Outcome $outcome, AttributionSpec $spec): void
    {
        $this->requireKey($spec->idempotencyKey);
        if (trim($spec->actionRef) === '' || strlen($spec->actionRef) > 191) {
            throw new InvalidArgumentException('An attribution needs the action\'s durable reference (1 to 191 characters).');
        }
        if ($spec->proposalRef !== null && ($spec->actionType !== ActionType::AgentRun || strlen($spec->proposalRef) > 64)) {
            throw new InvalidArgumentException('A proposal reference names one proposal of an agent run (at most 64 characters).');
        }
        if (trim($spec->methodVersion) === '' || strlen($spec->methodVersion) > 32) {
            throw new InvalidArgumentException('An attribution names the version of the method that made it.');
        }
        // Q3 / C6: a method the running policy does not admit is refused before its confidence rule is read.
        AttributionPolicy::requireActive($spec->method);
        if (($violation = $spec->method->confidenceViolation($spec->confidence)) !== null) {
            throw new InvalidArgumentException("Attribution refused: {$violation}.");
        }
        if ($spec->method === AttributionMethod::OperatorAsserted && (! ($spec->actor?->isHuman() ?? false) || trim((string) $spec->reason) === '')) {
            throw new InvalidArgumentException('An operator-asserted attribution needs a human actor and a reason.');
        }
        if ($spec->correlationId !== null && strlen($spec->correlationId) > 128) {
            throw new InvalidArgumentException('A correlation id is at most 128 characters.');
        }
        $this->requireEvidence($spec->evidence);

        $owner = $this->actionBusiness($spec->actionType, $spec->actionId);
        if ($owner === false) {
            throw new InvalidArgumentException("The [{$spec->actionType->value}] action {$spec->actionId} does not exist.");
        }
        // ⛔ Q6 (docs/110 §3, docs/115 §3): an action with no business of its own is refused, fail closed. docs/107
        //    accepted it as "an HQ-scope action"; the ruling reversed that. An explicit, validated HQ-scope
        //    declaration is the only future way in, and no v1 recorder needs one, so none exists.
        if ($owner === null) {
            throw new ImplicitHqScopeRefused("The [{$spec->actionType->value}] action {$spec->actionId} has no business of its own, and an attribution to business "
                .(int) $outcome->business_id.' may not rest on an implicit HQ scope (ruling Q6). Crediting an HQ-wide action needs an explicit, validated HQ-scope declaration, which v1 does not define.');
        }
        if ($owner !== (int) $outcome->business_id) {
            throw new InvalidArgumentException("The [{$spec->actionType->value}] action {$spec->actionId} belongs to another business.");
        }
    }

    /** The business that owns a referenced read-model row; false when the row does not exist. */
    private function resultBusiness(ResultType $type, int $id): int|false|null
    {
        $row = match ($type) {
            ResultType::ShopOrder, ResultType::ErpOrder => DB::table('facts_orders')->where('id', $id)->select('business_id')->first(),
            ResultType::Conversation => DB::table('conversations')->where('id', $id)->select('business_id')->first(),
            ResultType::ConversationMessage => DB::table('conversation_messages as m')->join('conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('m.id', $id)->select('c.business_id')->first(),
            ResultType::ContentPublication => $this->publicationBusiness($id),
            ResultType::External => null,
        };

        return $row === null ? false : ($row->business_id === null ? null : (int) $row->business_id);
    }

    /** The business an action belongs to: null when it has none of its own (refused, Q6), false when it does not exist. */
    private function actionBusiness(ActionType $type, int $id): int|false|null
    {
        $row = match ($type) {
            ActionType::DecisionRecord => DB::table('decision_records')->where('id', $id)->selectRaw('null as business_id')->first(),
            ActionType::ContentPublication => $this->publicationBusiness($id),
            ActionType::ConversationMessage => DB::table('conversation_messages as m')->join('conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('m.id', $id)->select('c.business_id')->first(),
            default => DB::table($type->table())->where('id', $id)->select('business_id')->first(),
        };

        return $row === null ? false : ($row->business_id === null ? null : (int) $row->business_id);
    }

    private function publicationBusiness(int $id): ?object
    {
        return DB::table('content_publications as p')
            ->join('platform_accounts as a', 'a.id', '=', 'p.platform_account_id')
            ->join('brands as b', 'b.id', '=', 'a.brand_id')
            ->where('p.id', $id)->select('b.business_id')->first();
    }

    private function requireSourceSystem(string $system): void
    {
        if (! in_array($system, self::SOURCE_SYSTEMS, true)) {
            throw new InvalidArgumentException("[{$system}] is not a known source system (".implode(', ', self::SOURCE_SYSTEMS).').');
        }
    }

    private function requireKey(string $key): void
    {
        if (trim($key) === '' || strlen($key) > 191) {
            throw new InvalidArgumentException('An idempotency key is required (1 to 191 characters), composed from the source identity.');
        }
    }

    /** Only an asserted row is corrected or retracted; a retraction is final (reinstating is a new record). */
    private function requireAsserted(Outcome|OutcomeAttribution $prior): void
    {
        if ($prior->state !== OutcomeState::Asserted) {
            throw new InvalidArgumentException('A retracted row is final: record the outcome or attribution again under a new key instead.');
        }
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A correction or retraction needs a reason.');
        }
    }

    /** @param  array<string, mixed>|null  $evidence */
    private function requireEvidence(?array $evidence): void
    {
        if ($evidence !== null && strlen(CanonicalPayload::encode($evidence)) > self::MAX_EVIDENCE_BYTES) {
            throw new InvalidArgumentException('Evidence is identifiers and digests, at most 16 KB; it is not a place to copy business data.');
        }
    }

    private function utc(DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->utc();
    }

    private function withActor(OutcomeSpec $spec, OutcomeActor $actor): OutcomeSpec
    {
        return new OutcomeSpec($spec->type, $spec->businessId, $spec->occurredAt, $spec->source, $spec->sourceSystem, $spec->provenance,
            $spec->idempotencyKey, $spec->results, $spec->brandId, $spec->storeId, $spec->sourceAsOf, $spec->sourceRef, $spec->evidence,
            $actor, $spec->correlationId);
    }

    private function audit(string $action, Outcome|OutcomeAttribution $row, Outcome|OutcomeAttribution $prior, string $reason, OutcomeActor $actor): void
    {
        $this->auditor->record(
            action: $action,
            target: $row,
            before: ['id' => $prior->id, 'idempotency_key' => $prior->idempotency_key, 'content_digest' => $prior->content_digest],
            after: ['id' => $row->id, 'idempotency_key' => $row->idempotency_key, 'content_digest' => $row->content_digest, 'state' => $row->state->value],
            reason: $reason,
            actorUserId: $actor->isHuman() ? $actor->id : null,
            context: ['business_id' => $row->business_id],
            actorLabel: $actor->label,
        );
    }
}
