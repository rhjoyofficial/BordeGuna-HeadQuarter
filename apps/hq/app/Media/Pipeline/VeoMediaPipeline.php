<?php

namespace App\Media\Pipeline;

use App\Gateway\Credentials\CredentialVault;
use App\Media\Pipeline\Exceptions\MediaPipelineRefused;
use App\Media\Pipeline\Exceptions\MediaPipelineUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Veo through the Gemini API (docs/97 section 2.2: Veo 3.1 Fast is the recommended first provider, billed
 * to the same Google key). The long-running pattern: `POST {base}/models/{model}:predictLongRunning` with
 * `{"instances":[{"prompt":...}],"parameters":{"aspectRatio":...}}` answers `{"name":"operations/..."}`; the
 * operation is read with `GET {base}/{name}` until `done`, and the result carries the video file's URI under
 * `response.generateVideoResponse.generatedSamples[0].video.uri`, downloaded with the same key.
 *
 * ⚠ The field names above are from the Gemini API's published video-generation guide and are PROVEN only on
 * the first live call (the model name and base URL are configuration, `hq.media.veo`); until then the contract
 * tests prove HQ's own handling against these shapes, not Google's (docs/144-media-pipeline).
 * ⚠ The key is read only from the open vault scope, sent in the `x-goog-api-key` header, and never appears in
 * a message. Credential kind `gemini_video_key`, account `hq`.
 */
final class VeoMediaPipeline implements MediaPipeline
{
    public const PROVIDER = 'veo';

    public const CREDENTIAL = 'gemini_video_key';

    public const ACCOUNT_REF = 'hq';

    /** An operation name and nothing else: no dots, no traversal, no other path under the API. */
    private const OPERATION = '#^(models/[A-Za-z0-9.\-]+/)?operations/[A-Za-z0-9_\-]+$#';

    public function __construct(private readonly CredentialVault $vault) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function start(MediaJobSpec $spec): string
    {
        $key = $this->vault->secret(self::CREDENTIAL, self::ACCOUNT_REF);
        $model = (string) config('hq.media.veo.model', 'veo-3.1-fast-generate-preview');
        if (preg_match('/^[a-z0-9.\-]+$/', $model) !== 1) {
            throw new MediaPipelineRefused('veo: the configured model name is not a model id.');
        }

        $response = $this->call(fn () => Http::withHeaders(['x-goog-api-key' => $key])->acceptJson()->timeout((int) config('hq.media.veo.timeout', 30))
            ->post($this->base()."/models/{$model}:predictLongRunning", [
                'instances' => [['prompt' => $spec->brief]],
                'parameters' => ['aspectRatio' => $spec->aspect],
            ]));

        $name = $response->json('name');
        if (! is_string($name) || preg_match(self::OPERATION, $name) !== 1) {
            throw new MediaPipelineRefused('veo: the answer has no operation name.');
        }

        return $name;
    }

    public function check(string $handle): MediaJobStatus
    {
        if (preg_match(self::OPERATION, $handle) !== 1) {
            throw new MediaPipelineRefused('veo: this is not an operation name.');
        }
        $key = $this->vault->secret(self::CREDENTIAL, self::ACCOUNT_REF);
        $headers = ['x-goog-api-key' => $key];

        $op = $this->call(fn () => Http::withHeaders($headers)->acceptJson()->timeout(30)->get($this->base().'/'.$handle));
        if ($op->json('done') !== true) {
            return new MediaJobStatus(MediaJobStatus::RUNNING);
        }
        if ($op->json('error') !== null) {
            return new MediaJobStatus(MediaJobStatus::FAILED, error: substr((string) ($op->json('error.message') ?? 'The provider reported an error.'), 0, 200));
        }
        $uri = $op->json('response.generateVideoResponse.generatedSamples.0.video.uri');
        if (! is_string($uri) || ! str_starts_with($uri, 'https://')) {
            return new MediaJobStatus(MediaJobStatus::FAILED, error: 'The provider finished without a video file.');
        }

        $file = $this->call(fn () => Http::withHeaders($headers)->timeout((int) config('hq.media.veo.download_timeout', 120))->get($uri));

        return new MediaJobStatus(MediaJobStatus::READY, $file->body(), 'video/mp4');
    }

    private function base(): string
    {
        return rtrim((string) config('hq.media.veo.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    }

    /** @param  callable(): Response  $call */
    private function call(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new MediaPipelineUnavailable('veo: the provider could not be reached.');
        } catch (Throwable $e) {
            throw new MediaPipelineUnavailable('veo: '.$e::class);
        }
        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new MediaPipelineUnavailable("veo answered {$status}.");
        }
        if ($response->failed()) {
            throw new MediaPipelineRefused("veo refused the request ({$status}).");
        }

        return $response;
    }
}
