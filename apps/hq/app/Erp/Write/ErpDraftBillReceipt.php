<?php

namespace App\Erp\Write;

final readonly class ErpDraftBillReceipt
{
    public function __construct(public string $reference, public string $status = 'draft') {}
}
