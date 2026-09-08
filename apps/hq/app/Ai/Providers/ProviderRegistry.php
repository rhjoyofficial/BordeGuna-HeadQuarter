<?php

namespace App\Ai\Providers;

use InvalidArgumentException;

/**
 * The provider drivers HQ knows about, keyed by the name that appears in `model_tiers.provider`.
 *
 * Mirrors `ToolRegistry` on purpose: config lists classes, the container resolves them so drivers
 * get their dependencies injected, and each instance names itself. A registry that took keys from
 * config would let a typo silently disable a provider.
 */
final class ProviderRegistry
{
    /** @var array<string, ModelProvider> */
    private array $providers = [];

    /** @param list<class-string<ModelProvider>> $classes */
    public function __construct(array $classes = [])
    {
        foreach ($classes as $class) {
            $this->register($class);
        }
    }

    public function register(string $class): void
    {
        $instance = app($class);

        if (! $instance instanceof ModelProvider) {
            throw new InvalidArgumentException("[{$class}] is registered as a model provider but does not implement ModelProvider.");
        }

        $this->providers[$instance->key()] = $instance;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): ModelProvider
    {
        return $this->providers[$key]
            ?? throw new InvalidArgumentException("[{$key}] is not a registered model provider. Add its driver to config/hq.php under ai.providers.");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    /** @return array<string, bool> which providers hold a usable credential right now */
    public function availability(): array
    {
        $out = [];
        foreach ($this->providers as $key => $provider) {
            $out[$key] = $provider->available();
        }

        return $out;
    }

    /** @return list<string> providers that could actually serve a call */
    public function configured(): array
    {
        return array_keys(array_filter($this->availability()));
    }
}
