<?php

namespace App\Tools;

use InvalidArgumentException;

/**
 * Registry of executable tools. Populated from config('hq.tools'); a tool absent from the
 * registry cannot be scheduled or run, which is the Phase 0 form of the kill switch.
 */
final class ToolRegistry
{
    /** @var array<string, class-string<Tool>> */
    private array $classes = [];

    /** @var array<string, Tool> */
    private array $instances = [];

    /** @param array<string, class-string<Tool>> $classes */
    public function __construct(array $classes = [])
    {
        foreach ($classes as $class) {
            $this->register($class);
        }
    }

    /** @param class-string<Tool> $class */
    public function register(string $class): void
    {
        $instance = app($class);
        if (! $instance instanceof Tool) {
            throw new InvalidArgumentException("{$class} does not implement ".Tool::class);
        }
        $this->classes[$instance->key()] = $class;
        $this->instances[$instance->key()] = $instance;
    }

    public function has(string $key): bool
    {
        return isset($this->instances[$key]);
    }

    public function get(string $key): Tool
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown tool [{$key}]. It is not registered in config/hq.php.");
        }

        return $this->instances[$key];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->instances);
    }
}
