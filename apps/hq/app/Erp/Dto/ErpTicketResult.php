<?php

namespace App\Erp\Dto;

final readonly class ErpTicketResult
{
    public function __construct(public int $id, public string $ticketNumber, public bool $replayed) {}
}
