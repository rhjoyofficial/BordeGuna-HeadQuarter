<?php

namespace App\Content\Publishing;

use App\Gateway\Credentials\CredentialVault;
use App\Platform\Capabilities;
use App\Platform\Contracts\PublishingPlatform;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Publishes a single-image post to an Instagram professional account (docs/85 C.13). ⛔ REGISTERED NOWHERE.
 *
 * As Meta's content publishing guide states it (read 22 September 2026,
 * developers.facebook.com/docs/instagram-platform/content-publishing): step 1 `POST /{IG_ID}/media` with
 * `image_url` (a public URL Meta fetches) and `caption`, answered `{"id": "<IG_CONTAINER_ID>"}`; step 2
 * `POST /{IG_ID}/media_publish` with `creation_id`, answered `{"id": "<IG_MEDIA_ID>"}`; `Authorization:
 * Bearer <token>`; host graph.facebook.com with Facebook Login. "JPEG is the only image format
 * supported." Usage is readable at `GET /{IG_ID}/content_publishing_limit` (100 API posts per 24 hours).
 * ⚠ UNVERIFIED on that page: `GET /{media_id}?fields=id,caption,timestamp` and `GET /{IG_ID}/media` for
 * the read-back and the reconciliation; to be proven at gate P6-G3.
 *
 * A container that fails means no post was made (`failed`); a lost answer to the publish step means one
 * may have been (`uncertain`).
 */
final class InstagramPublisher implements PostPublisher
{
    public const CREDENTIAL = 'meta_instagram_token';

    public function __construct(private readonly CredentialVault $vault) {}

    public function platform(): string
    {
        return 'instagram';
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
        return [PublishingPlatform::PUBLISH];
    }

    public function publish(PublishRequest $request): PublishOutcome
    {
        if ($request->imageUrl === null || ! str_starts_with($request->imageUrl, 'https://')) {
            return PublishOutcome::failed('An Instagram post needs its JPEG image at a public https URL.');
        }

        $token = $this->vault->secret(self::CREDENTIAL, $request->accountRef);

        try {
            $container = $this->graph($token)->post("/{$request->accountRef}/media", [
                'image_url' => $request->imageUrl,
                'caption' => $request->text,
            ]);
        } catch (ConnectionException $e) {
            // No publish call was made, so no post exists: a container alone is never shown.
            return PublishOutcome::failed('The media container was not created: '.$e->getMessage());
        }

        $containerId = $container->json('id');
        if (! $container->successful() || ! is_string($containerId)) {
            return PublishOutcome::failed('The media container was refused: '.((string) ($container->json('error.message') ?? "HTTP {$container->status()}")));
        }

        try {
            $published = $this->graph($token)->post("/{$request->accountRef}/media_publish", ['creation_id' => $containerId]);
        } catch (ConnectionException $e) {
            return PublishOutcome::uncertain('The publish step did not answer; the post may exist: '.$e->getMessage());
        }

        return FacebookPagePublisher::outcome($published);
    }

    public function readBack(string $postId, string $accountRef): ?PostReadBack
    {
        $response = $this->graph($this->vault->secret(self::CREDENTIAL, $accountRef))->get("/{$postId}", ['fields' => 'id,caption,timestamp']);
        if (! $response->successful() || ! is_string($response->json('id'))) {
            return null;
        }

        return new PostReadBack((string) $response->json('id'), $response->json('caption'), $response->json('timestamp'));
    }

    public function recent(string $accountRef, CarbonImmutable $since): array
    {
        $response = $this->graph($this->vault->secret(self::CREDENTIAL, $accountRef))
            ->get("/{$accountRef}/media", ['fields' => 'id,caption,timestamp', 'limit' => 25]);

        $out = [];
        foreach ((array) $response->json('data', []) as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                continue;
            }
            $at = isset($row['timestamp']) ? CarbonImmutable::parse((string) $row['timestamp']) : null;
            if ($at !== null && $at->lessThan($since)) {
                continue;
            }
            $out[] = new PostReadBack($row['id'], $row['caption'] ?? null, $row['timestamp'] ?? null);
        }

        return $out;
    }

    private function graph(string $token): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('content.publishing.graph_base_url'), '/').'/'.config('content.publishing.graph_version'))
            ->timeout((int) config('content.publishing.timeout_seconds', 20))
            ->withToken($token)
            ->acceptJson();
    }
}
