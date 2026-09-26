<?php

namespace App\Content\Publishing;

use App\Gateway\Credentials\CredentialVault;
use App\Platform\Capabilities;
use App\Platform\Contracts\PublishingPlatform;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Publishes a text post to a Facebook Page (docs/85 C.13). ⛔ REGISTERED NOWHERE.
 *
 * As Meta's Pages API guide states it (read 22 September 2026, developers.facebook.com/docs/pages-api/posts,
 * examples on v25.0): `POST /{page_id}/feed` with `message`, `published=true` and a Page access token;
 * the answer is `{"id": "page_post_id"}`; the permissions are `pages_manage_posts` and
 * `pages_manage_engagement`, and the app user needs `CREATE_CONTENT` on the Page. The guide lists a
 * Page's posts with `GET /{page_id}/feed` returning `id`, `created_time` and `message`.
 * ⚠ UNVERIFIED on that page: reading ONE post by `GET /{post_id}?fields=id,message,created_time`; it is
 * the Graph API's ordinary node read and is what `readBack()` does, to be proven at gate P6-G3.
 *
 * An answer lost in transit, or a server error, is `uncertain` (the post may exist); a client error
 * is `failed` (it was refused). Neither is ever called published here.
 */
final class FacebookPagePublisher implements PostPublisher
{
    public const CREDENTIAL = 'meta_page_token';

    public function __construct(private readonly CredentialVault $vault) {}

    public function platform(): string
    {
        return 'facebook_page';
    }

    public function credentialProvider(): string
    {
        return self::CREDENTIAL;
    }

    public function ceiling(): Capabilities
    {
        return Capabilities::of([PublishingPlatform::PUBLISH, PublishingPlatform::READ_INSIGHTS]);
    }

    public function requiresApproval(): array
    {
        // Meta's own review of the app's Page permissions (docs/82 S2).
        return [PublishingPlatform::PUBLISH];
    }

    public function publish(PublishRequest $request): PublishOutcome
    {
        $token = $this->vault->secret(self::CREDENTIAL, $request->accountRef);

        try {
            $response = $this->graph()->asForm()->post("/{$request->accountRef}/feed", [
                'message' => $request->text,
                'published' => 'true',
                'access_token' => $token,
            ]);
        } catch (ConnectionException $e) {
            return PublishOutcome::uncertain('The Page did not answer; the post may exist: '.$e->getMessage());
        }

        return self::outcome($response);
    }

    public function readBack(string $postId, string $accountRef): ?PostReadBack
    {
        $token = $this->vault->secret(self::CREDENTIAL, $accountRef);
        $response = $this->graph()->get("/{$postId}", ['fields' => 'id,message,created_time', 'access_token' => $token]);
        if (! $response->successful() || ! is_string($response->json('id'))) {
            return null;
        }

        return new PostReadBack((string) $response->json('id'), $response->json('message'), $response->json('created_time'));
    }

    public function recent(string $accountRef, CarbonImmutable $since): array
    {
        $token = $this->vault->secret(self::CREDENTIAL, $accountRef);
        $response = $this->graph()->get("/{$accountRef}/feed", [
            'fields' => 'id,message,created_time', 'since' => $since->getTimestamp(), 'limit' => 25, 'access_token' => $token,
        ]);

        $out = [];
        foreach ((array) $response->json('data', []) as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $out[] = new PostReadBack($row['id'], $row['message'] ?? null, $row['created_time'] ?? null);
            }
        }

        return $out;
    }

    private function graph(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('content.publishing.graph_base_url'), '/').'/'.config('content.publishing.graph_version'))
            ->timeout((int) config('content.publishing.timeout_seconds', 20));
    }

    public static function outcome(Response $response): PublishOutcome
    {
        if ($response->serverError()) {
            return PublishOutcome::uncertain("The platform answered HTTP {$response->status()}; the post may exist.");
        }
        if (! $response->successful()) {
            return PublishOutcome::failed('The platform refused the post: '.((string) ($response->json('error.message') ?? "HTTP {$response->status()}")));
        }
        $id = $response->json('id');

        return is_string($id) && $id !== '' ? PublishOutcome::accepted($id) : PublishOutcome::uncertain('The platform answered without a post id.');
    }
}
