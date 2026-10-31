<?php

namespace App\Ingestion;

use App\Connectors\Capabilities;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequest;
use App\Context\Provenance;
use App\Ingestion\Extraction\BillExtractor;
use App\Ingestion\Extraction\DocumentTextExtractor;
use App\Ingestion\Extraction\ExtractionVerifier;
use App\Ingestion\Extraction\RuleBasedBillExtractor;
use App\Ingestion\Mailbox\Exceptions\MailboxRefused;
use App\Ingestion\Mailbox\InboundAttachment;
use App\Ingestion\Mailbox\MailboxConnector;
use App\Media\MediaAssetService;
use App\Media\Upload\AttachmentGuard;
use App\Media\Upload\AttachmentRejected;
use App\Media\Upload\AttachmentScanner;
use App\Media\Upload\Scanners;
use App\Models\Business;
use App\Models\IngestedDocument;
use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads a business's supplier mailbox and turns bill attachments into DRAFTS for a person (docs/144-email-ingestion).
 *
 * ⛔ **Drafts, never actions.** The only things written are HQ's own rows (the mail copy, the stored attachment,
 * the extracted draft). Nothing is replied to, deleted or marked in the mailbox, and nothing reaches the ERP:
 * a reviewed draft is a separate, approved act (`erp.create_draft_bill`).
 * ⛔ **Scoped.** Every row carries the business; the mailbox credential is the business's own (the registry
 * resolves it by business), so one business's mail can never be read into another's.
 * ⭐ **Idempotent.** A message already read (same business, connector and external id) is skipped, so polling
 * twice, or a mailbox that re-lists old mail, writes nothing twice.
 * ⭐ **Safe files.** An attachment passes the byte-level guard (PDF without active content, text, images) and the
 * scanner before it is stored; anything else is recorded with the reason and not kept.
 *
 * Must run inside a gateway call that opened the mailbox credential.
 */
final class MailIngestor
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly AttachmentGuard $guard,
        private readonly MediaAssetService $assets,
        private readonly DocumentTextExtractor $text,
        private readonly ExtractionVerifier $verifier,
        private readonly Auditor $auditor,
        private readonly BillExtractor $extractor = new RuleBasedBillExtractor,
    ) {}

    /**
     * @return array{messages: int, attachments: int, drafts: int, rejected: int, provider: string}
     *
     * @throws MailboxRefused when no mailbox connector is ready for this business
     */
    public function ingest(Business $business, int $limit = 20): array
    {
        $resolution = $this->registry->resolve(new ConnectorRequest(Capabilities::MAIL_INBOX_READ, $business->id));
        if (! $resolution->resolvedOk()) {
            throw new MailboxRefused("No mailbox connector is ready for {$business->slug}: ".($resolution->reason ?? $resolution->outcome->value));
        }
        $mailbox = $this->registry->adapter($resolution);
        if (! $mailbox instanceof MailboxConnector) {
            throw new MailboxRefused('The mailbox connector is not a mailbox connector.');
        }
        $provider = (string) $resolution->connector?->provider;

        $since = MailMessage::query()->where('business_id', $business->id)->where('connector', $provider)->max('received_at');
        $batch = $mailbox->fetch($business->slug, $since === null ? null : new \DateTimeImmutable((string) $since), max(1, min($limit, 50)));

        $counts = ['messages' => 0, 'attachments' => 0, 'drafts' => 0, 'rejected' => 0, 'provider' => $provider];
        foreach ($batch->messages as $mail) {
            if (MailMessage::query()->where('business_id', $business->id)->where('connector', $provider)->where('external_id', $mail->externalId)->exists()) {
                continue;
            }
            DB::transaction(function () use ($business, $provider, $mail, &$counts) {
                $message = MailMessage::create([
                    'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'connector' => $provider, 'external_id' => $mail->externalId,
                    'from_address' => $mail->from === null ? null : mb_substr($mail->from, 0, 191), 'subject' => $mail->subject === null ? null : mb_substr($mail->subject, 0, 255),
                    'received_at' => $mail->receivedAt, 'body_text' => $mail->bodyText === null ? null : mb_substr($mail->bodyText, 0, 20000),
                ]);
                $counts['messages']++;
                foreach ($mail->attachments as $attachment) {
                    $counts['attachments']++;
                    $this->attachment($business, $message, $attachment) ? $counts['drafts']++ : $counts['rejected']++;
                }
            });
        }
        $this->auditor->recordSystem('mail.polled', null, null, $counts + ['business_id' => $business->id]);

        return $counts;
    }

    /** True when a draft was made. */
    private function attachment(Business $business, MailMessage $message, InboundAttachment $file): bool
    {
        $row = new MailAttachment([
            'mail_message_id' => $message->id, 'business_id' => $business->id, 'filename' => mb_substr(basename(str_replace('\\', '/', $file->filename)), 0, 191),
            'mime' => mb_substr($file->declaredMime, 0, 96), 'bytes' => strlen($file->bytes), 'sha256' => hash('sha256', $file->bytes),
        ]);

        try {
            ['mime' => $mime, 'kind' => $kind] = $this->guard->inspect($file->bytes, ['document', 'image']);
            [$verdict, $detail] = Scanners::make()->scan($file->bytes);
            $row->scan_status = $verdict;
            if ($verdict === AttachmentScanner::INFECTED) {
                throw new AttachmentRejected('The virus scan found a threat ('.$detail.').');
            }
            if ($verdict === AttachmentScanner::UNSCANNED && config('hq.media.scan_required', false)) {
                throw new AttachmentRejected('The file could not be virus-scanned.');
            }
        } catch (AttachmentRejected $e) {
            $row->rejected_reason = mb_substr($e->getMessage(), 0, 191);
            $row->save();

            return false;
        }

        $asset = $this->assets->store($file->bytes, $mime, $kind, Provenance::ThirdParty, [
            'business_id' => $business->id, 'source_ref' => 'mail:'.$message->uuid, 'retention_days' => (int) config('hq.ingestion.retention_days', 365),
        ]);
        $row->media_asset_id = $asset->id;

        $text = $this->text->text($file->bytes, $mime);
        if ($text === null) {
            $row->rejected_reason = 'No readable text (a scanned image or an empty PDF needs OCR).';
            $row->save();

            return false;
        }

        $extracted = $this->verifier->verify($this->extractor->extract($text), $text);
        if ($extracted['invoice_number'] === null && $extracted['total'] === null && $extracted['lines'] === []) {
            $row->rejected_reason = 'This does not read as a supplier bill.';
            $row->save();

            return false;
        }
        $row->save();

        $document = IngestedDocument::create([
            'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'mail_message_id' => $message->id, 'mail_attachment_id' => $row->id,
            'kind' => 'supplier_bill', 'status' => IngestedDocument::DRAFT, 'source_text' => $text, 'extracted' => $extracted, 'extractor' => $this->extractor->key(),
        ]);
        $this->auditor->recordSystem('ingestion.draft_created', $document, null, ['business_id' => $business->id, 'kind' => 'supplier_bill', 'warnings' => count($extracted['warnings'])]);

        return true;
    }
}
