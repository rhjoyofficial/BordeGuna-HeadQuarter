<?php

namespace Tests\Support\Ai;

use App\Ai\Exceptions\ProviderRefused;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Providers\ModelProvider;
use App\Ai\Providers\ModelRequest;
use App\Ai\Providers\ModelResponse;
use Throwable;

/**
 * A provider that answers from a script, so routing, fallback, cost and repair can be tested without
 * a network or a key.
 *
 * ⚠ It deliberately does NOT fake HTTP. The real driver's HTTP handling is tested separately with
 * `Http::fake()`; this exists to test everything ABOVE the driver, and mixing the two would leave
 * both half-tested.
 *
 * ⚠ State is keyed by provider name rather than held in plain statics, because a subclass shares its
 * parent's static properties in PHP, and two providers sharing one call counter would make every
 * fallback test agree with itself for the wrong reason.
 */
class FixtureProvider implements ModelProvider
{
    public const KEY = 'fixture';

    /** @var array<string, list<ModelResponse|Throwable>> answers in order; the last one repeats */
    public static array $scripts = [];

    /** @var array<string, int> */
    public static array $calls = [];

    /** @var array<string, list<ModelRequest>> */
    public static array $seen = [];

    /** @var array<string, bool> */
    public static array $configured = [];

    public static function reset(): void
    {
        self::$scripts = [];
        self::$calls = [];
        self::$seen = [];
        self::$configured = [];
    }

    public static function script(string $provider, array $answers): void
    {
        self::$scripts[$provider] = $answers;
    }

    public static function callsTo(string $provider): int
    {
        return self::$calls[$provider] ?? 0;
    }

    /** @return list<ModelRequest> */
    public static function requestsTo(string $provider): array
    {
        return self::$seen[$provider] ?? [];
    }

    public static function configure(string $provider, bool $available): void
    {
        self::$configured[$provider] = $available;
    }

    public static function reply(string $text, int $in = 100, int $out = 20, int $cached = 0): ModelResponse
    {
        return new ModelResponse($text, $in, $out, $cached, 42, ModelResponse::STOP_END_TURN);
    }

    /** A reply the provider cut off at the output limit: the text is whatever got out before the wall. */
    public static function truncated(string $text, int $in = 100, int $out = 4096): ModelResponse
    {
        return new ModelResponse($text, $in, $out, 0, 42, ModelResponse::STOP_MAX_TOKENS);
    }

    public static function outage(string $message = 'simulated outage'): ProviderUnavailable
    {
        return new ProviderUnavailable($message);
    }

    public static function refusal(string $message = 'simulated bad request'): ProviderRefused
    {
        return new ProviderRefused($message);
    }

    public function key(): string
    {
        return static::KEY;
    }

    public function available(): bool
    {
        return self::$configured[$this->key()] ?? true;
    }

    public function credentialPair(): array
    {
        return [$this->key().'_api_key', 'hq'];
    }

    public function complete(ModelRequest $request): ModelResponse
    {
        $key = $this->key();
        self::$seen[$key][] = $request;

        $script = self::$scripts[$key] ?? [];
        $index = self::$calls[$key] ?? 0;
        $answer = $script[$index] ?? ($script === [] ? self::reply('ok') : $script[array_key_last($script)]);

        self::$calls[$key] = $index + 1;

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
