<?php

namespace App\Gateway\Policy;

use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\ToolRequest;

/**
 * A tool-specific policy class, named by the tool's descriptor.
 *
 * ⚠ **It may only make the decision stricter.** A policy class that tried to turn a
 * `require_approval` baseline into an `allow` is a programming error, and the engine ignores the
 * attempt and records it rather than obeying it. Loosening happens exactly one way: an explicit,
 * audited `relax_to` guardrail rule that a human configured, and never for R5.
 *
 * No tool declares one today. The mechanism exists because the moment a tool needs
 * "approval above this value, automatic below it", that logic is code with tests rather than a
 * value in a JSON blob.
 */
interface ToolPolicy
{
    /**
     * @param  array<string, mixed>  $args  the VALIDATED arguments
     */
    public function evaluate(ToolDescriptor $descriptor, ToolRequest $request, array $args, ActingIdentity $identity, PolicyDecision $baseline): PolicyDecision;
}
