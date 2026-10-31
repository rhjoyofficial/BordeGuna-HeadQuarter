<?php

namespace App\Ingestion\Mailbox;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Gmail through its REST API, read-only (`gmail.readonly` scope is all it ever needs). List
 * `GET /gmail/v1/users/me/messages?q=has:attachment in:inbox after:<epoch>`, read each message with
 * `?format=full`, and each attachment with `/messages/{id}/attachments/{attachmentId}` (base64url `data`).
 * ⚠ The call shapes are Google's published ones and are PROVEN only on the first live call (docs/144-email-ingestion).
 *
 * Credential kind `gmail_mailbox_token`: a Gmail OAuth access token for the mailbox (refreshing it is the
 * operator's, until an OAuth refresh flow is built). Account: the business slug.
 */
final class GmailMailbox extends AbstractHttpMailbox
{
    public const PROVIDER = 'gmail';

    public const CREDENTIAL = 'gmail_mailbox_token';

    public function key(): string
    {
        return self::PROVIDER;
    }

    protected function credentialKind(): string
    {
        return self::CREDENTIAL;
    }

    public function fetch(string $accountRef, ?\DateTimeInterface $since, int $limit): MailboxBatch
    {
        $token = $this->token($accountRef);
        $base = rtrim((string) config('hq.ingestion.gmail.base_url', 'https://gmail.googleapis.com/gmail/v1'), '/').'/users/me';
        $query = 'has:attachment in:inbox'.($since === null ? '' : ' after:'.$since->getTimestamp());
        $http = fn () => Http::withToken($token)->acceptJson()->timeout((int) config('hq.ingestion.timeout', 30));

        $list = $this->guard(fn () => $http()->get($base.'/messages', ['q' => $query, 'maxResults' => max(1, min($limit, 50))]));

        $messages = [];
        foreach ((array) $list->json('messages', []) as $ref) {
            $id = is_array($ref) ? ($ref['id'] ?? null) : null;
            if (! is_string($id) || preg_match('/^[A-Za-z0-9_\-]+$/', $id) !== 1) {
                continue;
            }
            $full = $this->guard(fn () => $http()->get($base.'/messages/'.$id, ['format' => 'full']))->json();
            $messages[] = $this->message($id, (array) $full, $base, $http);
        }
        usort($messages, static fn (InboundMail $a, InboundMail $b) => $a->receivedAt <=> $b->receivedAt);

        return new MailboxBatch(array_slice($messages, 0, $limit));
    }

    /** @param  callable(): \Illuminate\Http\Client\PendingRequest  $http */
    private function message(string $id, array $full, string $base, callable $http): InboundMail
    {
        $headers = [];
        foreach ((array) data_get($full, 'payload.headers', []) as $h) {
            if (is_array($h) && is_string($h['name'] ?? null)) {
                $headers[strtolower($h['name'])] = (string) ($h['value'] ?? '');
            }
        }
        $body = null;
        $attachments = [];
        $walk = function (array $part) use (&$walk, &$body, &$attachments, $id, $base, $http) {
            $mime = (string) ($part['mimeType'] ?? '');
            $filename = (string) ($part['filename'] ?? '');
            $data = data_get($part, 'body.data');
            $attachmentId = data_get($part, 'body.attachmentId');
            if ($filename !== '' && is_string($attachmentId) && preg_match('/^[A-Za-z0-9_\-]+$/', $attachmentId) === 1) {
                $file = $this->guard(fn () => $http()->get($base.'/messages/'.$id.'/attachments/'.$attachmentId))->json('data');
                if (is_string($file)) {
                    $attachments[] = new InboundAttachment($filename, $mime, self::decode($file));
                }
            } elseif ($mime === 'text/plain' && $body === null && is_string($data)) {
                $body = self::decode($data);
            }
            foreach ((array) ($part['parts'] ?? []) as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk((array) ($full['payload'] ?? []));

        $ms = (int) ($full['internalDate'] ?? 0);

        return new InboundMail($id, $headers['from'] ?? null, $headers['subject'] ?? null, CarbonImmutable::createFromTimestampMs($ms), $body, $attachments);
    }
}
