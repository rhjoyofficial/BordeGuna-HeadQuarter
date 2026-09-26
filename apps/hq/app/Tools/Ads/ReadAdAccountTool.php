<?php

namespace App\Tools\Ads;

use App\Ads\AdsReader;
use App\Models\PlatformAccount;
use App\Platform\Contracts\AdsPlatform;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * An ad account's currency, time zone, status and the OWNER's spending limit, read live (R2).
 *
 * ⛔ The limit is read, never written (ADR-050 clarification 3). "No limit set" comes back as null,
 * never as zero, and never as room to spend (clarification 9).
 */
final class ReadAdAccountTool extends AdsTool
{
    public function key(): string
    {
        return 'ads.read_account';
    }

    protected function title(): string
    {
        return 'Read an ad account';
    }

    protected function purpose(): string
    {
        return 'Reads one connected ad account of a business, as the platform states it: currency, time zone, status, the owner\'s '
            .'account spending limit and the amount spent against it. Read-only; the limit is the owner\'s and is never changed here.';
    }

    protected function capability(): string
    {
        return AdsPlatform::READ_CAMPAIGNS;
    }

    protected function arguments(): array
    {
        return [];
    }

    protected function outputProperties(): array
    {
        return ['account' => ['type' => 'object']];
    }

    protected function read(AdsReader $reader, PlatformAccount $account, array $payload, ToolContext $context): array|ToolResult
    {
        return ['account' => $reader->account()->toArray()];
    }
}
