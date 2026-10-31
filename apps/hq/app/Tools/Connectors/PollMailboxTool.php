<?php

namespace App\Tools\Connectors;

use App\Gateway\OutcomeClass;
use App\Ingestion\MailIngestor;
use App\Ingestion\Mailbox\Exceptions\MailboxRefused;
use App\Ingestion\Mailbox\Exceptions\MailboxUnavailable;
use App\Ingestion\Mailbox\GmailMailbox;
use App\Ingestion\Mailbox\GraphMailbox;
use App\Models\Business;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * `mail.poll_inbox`: read the business's supplier mailbox and turn bill attachments into DRAFTS for a person to
 * review (docs/144-email-ingestion). Reads the mailbox only; sends, replies to, deletes and marks nothing in it;
 * writes only HQ's own rows; nothing reaches the ERP.
 */
final class PollMailboxTool extends ConnectorTool
{
    public function key(): string
    {
        return 'mail.poll_inbox';
    }

    protected function title(): string
    {
        return 'Read the supplier mailbox';
    }

    protected function purpose(): string
    {
        return 'Reads new mail with attachments from the business\'s supplier mailbox and makes DRAFT bills from readable attachments, '
            .'each field with the text it came from, for a person to review. Read-only at the mailbox; nothing is sent, deleted or marked.';
    }

    protected function flag(): string
    {
        return 'connectors.mail_ingestion';
    }

    protected function permission(): string
    {
        return 'hq.tools.mail.ingest';
    }

    protected function credentialSources(): array
    {
        $business = 'args.business_slug|business.slug';

        return [GmailMailbox::CREDENTIAL => $business, GraphMailbox::CREDENTIAL => $business];
    }

    protected function arguments(): array
    {
        return ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]];
    }

    protected function timeoutSeconds(): int
    {
        return 120;
    }

    protected function run(?Business $business, array $payload, ToolContext $context): ToolResult
    {
        try {
            return ToolResult::succeeded(app(MailIngestor::class)->ingest($business, (int) ($payload['limit'] ?? 20)));
        } catch (MailboxRefused $e) {
            return ToolResult::failed($e->getMessage(), ['connector_state' => 'refused'], OutcomeClass::ConnectorError);
        } catch (MailboxUnavailable $e) {
            return ToolResult::failed($e->getMessage(), ['connector_state' => 'unavailable'], OutcomeClass::ProviderUnavailable);
        }
    }
}
