<?php

namespace App\Ingestion\Mailbox;

use App\Gateway\Credentials\CredentialVault;
use App\Ingestion\Mailbox\Exceptions\MailboxRefused;
use App\Ingestion\Mailbox\Exceptions\MailboxUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** What the REST mailbox drivers share: the token only from the open vault scope, and failures in two classes. */
abstract class AbstractHttpMailbox implements MailboxConnector
{
    public function __construct(protected readonly CredentialVault $vault) {}

    abstract protected function credentialKind(): string;

    protected function token(string $accountRef): string
    {
        return $this->vault->secret($this->credentialKind(), $accountRef);
    }

    /** @param  callable(): Response  $call */
    protected function guard(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new MailboxUnavailable($this->key().': the mailbox could not be reached.');
        } catch (Throwable $e) {
            throw new MailboxUnavailable($this->key().': '.$e::class);
        }
        $status = $response->status();
        if ($status === 401 || $status === 403) {
            throw new MailboxRefused($this->key().': the mailbox rejected the credential.');
        }
        if ($status === 429 || $status >= 500) {
            throw new MailboxUnavailable($this->key()." answered {$status}.");
        }
        if ($response->failed()) {
            throw new MailboxRefused($this->key()." refused the request ({$status}).");
        }

        return $response;
    }

    protected static function decode(string $base64Url): string
    {
        return (string) base64_decode(strtr($base64Url, '-_', '+/'), false);
    }
}
