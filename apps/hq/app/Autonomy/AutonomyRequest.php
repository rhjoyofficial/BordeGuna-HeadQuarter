<?php

namespace App\Autonomy;

use App\Context\ContextBundle;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Gateway\Descriptor\Channel;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\MonetaryImpact;
use App\Gateway\Descriptor\ToolDescriptor;
use DateTimeInterface;

/**
 * Everything the resolver needs to know about one proposed action (ADR-020).
 *
 * The twelve dimensions `docs/53` identified, in one object, so that adding a thirteenth is a
 * change here and in the grant table rather than a new argument threaded through five call sites.
 *
 * ⚠ Every field is nullable or defaulted, and every absent field makes the answer LOWER, never
 * higher: a grant that names a dimension the request cannot supply simply does not match, and a
 * request that matches no grant resolves to A0.
 */
final readonly class AutonomyRequest
{
    public function __construct(
        public ?string $agentKey = null,
        public ?ToolDescriptor $descriptor = null,
        public ?int $businessId = null,
        public ?int $brandId = null,
        public ?Channel $channel = null,
        public ?string $customerSegment = null,
        public ?string $environment = null,
        public ?DataClass $dataClass = null,
        public ?MonetaryImpact $monetaryImpact = null,
        public ?DateTimeInterface $at = null,
        /** The measured agreement rate for this combination, from the evaluation subsystem. */
        public ?float $agreementRate = null,
        /** The context that will be put in front of the model, carrying its provenance labels. */
        public ?ContextBundle $context = null,
        /** A ceiling imposed by compliance, e.g. consent withdrawn or a channel restriction. */
        public ?AutonomyLevel $complianceCeiling = null,
        /** The agent's own configured ceiling (`agents.max_autonomy_level`). */
        public ?AutonomyLevel $agentCeiling = null,
    ) {}

    public function toolKey(): ?string
    {
        return $this->descriptor?->key;
    }
}
