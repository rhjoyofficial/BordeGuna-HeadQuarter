<?php

namespace App\Ingestion\Mailbox;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft 365 / Outlook through Microsoft Graph, read-only (`Mail.Read`). List
 * `GET /me/mailFolders/inbox/messages?$filter=hasAttachments eq true and receivedDateTime gt <iso>&$orderby=receivedDateTime asc`,
 * attachments with `GET /me/messages/{id}/attachments` (`contentBytes`, base64, on a `fileAttachment`). The mailbox
 * is `me` for a delegated token, or `users/{upn}` when `hq.ingestion.graph.user` is set for an application token.
 * ⚠ The call shapes are Microsoft's published ones and are PROVEN only on the first live call (docs/144-email-ingestion).
 *
 * Credential kind `graph_mailbox_token`, account: the business slug.
 */
final class GraphMailbox extends AbstractHttpMailbox
{
    public const PROVIDER = 'msgraph';

    public const CREDENTIAL = 'graph_mailbox_token';

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
        $user = (string) config('hq.ingestion.graph.user', '');
        $root = rtrim((string) config('hq.ingestion.graph.base_url', 'https://graph.microsoft.com/v1.0'), '/')
            .($user === '' ? '/me' : '/users/'.rawurlencode($user));
        $http = fn () => Http::withToken($token)->acceptJson()->timeout((int) config('hq.ingestion.timeout', 30));

        $filter = 'hasAttachments eq true'.($since === null ? '' : ' and receivedDateTime gt '.CarbonImmutable::instance($since)->utc()->format('Y-m-d\TH:i:s\Z'));
        $list = $this->guard(fn () => $http()->get($root.'/mailFolders/inbox/messages', [
            '$filter' => $filter, '$orderby' => 'receivedDateTime asc', '$top' => max(1, min($limit, 50)),
            '$select' => 'id,subject,from,receivedDateTime,body',
        ]));

        $messages = [];
        foreach ((array) $list->json('value', []) as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            $files = $this->guard(fn () => $http()->get($root.'/messages/'.rawurlencode($id).'/attachments'))->json('value', []);
            $attachments = [];
            foreach ((array) $files as $file) {
                if (is_array($file) && str_ends_with((string) ($file['@odata.type'] ?? ''), 'fileAttachment') && is_string($file['contentBytes'] ?? null)) {
                    $attachments[] = new InboundAttachment((string) ($file['name'] ?? 'file'), (string) ($file['contentType'] ?? ''), (string) base64_decode($file['contentBytes'], false));
                }
            }
            $content = (string) data_get($row, 'body.content', '');
            $text = strtolower((string) data_get($row, 'body.contentType')) === 'html' ? trim(html_entity_decode(strip_tags($content))) : $content;

            $messages[] = new InboundMail($id, data_get($row, 'from.emailAddress.address'), $row['subject'] ?? null,
                CarbonImmutable::parse((string) ($row['receivedDateTime'] ?? 'now')), $text === '' ? null : $text, $attachments);
        }

        return new MailboxBatch(array_slice($messages, 0, $limit));
    }
}
