<?php

namespace App\Tools\Erp;

use App\Erp\ErpPlatform;
use App\Gateway\Descriptor\DataClass;
use App\Tools\ToolResult;

/**
 * Whether a phone names a customer in one business, the way the ERP's checkout would pick one, and
 * the eligibility facts the ERP actually holds (docs/81 C.5.3). It creates nobody.
 *
 * ⛔ NOT agent-executable. It takes a phone number and answers with whether a person exists and
 * their name, so an agent that could call it could enumerate customers by phone. It is a stage
 * gate P4-G3 decides after the `docs/79` G suite measures it, not a default.
 *
 * ⚠ The phone and the name are personal data: the phone is masked in the recorded arguments and
 * errors, and the phone and the name are masked in the recorded output, so neither reaches a
 * stored row. The caller who ran it sees the answer; the tables do not keep it.
 */
final class CheckOrderEligibilityTool extends ErpOrderReadTool
{
    public function key(): string
    {
        return 'erp.check_order_eligibility';
    }

    protected function title(): string
    {
        return 'Check order eligibility in the ERP';
    }

    protected function purpose(): string
    {
        return 'Says whether a phone number names an active customer in one business\'s ERP, the way the ERP\'s checkout would pick one, '
            .'and which eligibility facts the ERP holds and which it does not (no blocked, cash-on-delivery or credit-hold flag). Creates nobody. Read-only.';
    }

    protected function agentExecutable(): bool
    {
        return false;
    }

    protected function dataClass(): DataClass
    {
        return DataClass::Personal;
    }

    protected function redact(): array
    {
        return ['phone', 'phone_normalized', 'match.display_name'];
    }

    protected function arguments(): array
    {
        return ['phone' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 32]];
    }

    protected function requiredArguments(): array
    {
        return ['phone'];
    }

    protected function outputProperties(): array
    {
        return [
            'phone_matchable' => ['type' => 'boolean'],
            'customer_exists' => ['type' => 'boolean'],
            'match' => ['type' => ['object', 'null']],
            'would_create_new_customer' => ['type' => 'boolean'],
            'fields_the_erp_does_not_have' => ['type' => 'array'],
        ];
    }

    protected function read(ErpPlatform $erp, array $payload): array|ToolResult
    {
        $read = $erp->checkCustomerEligibility((string) $payload['phone']);

        return [$read, $read->data];
    }
}
