<?php

namespace App\Workflow;

use InvalidArgumentException;

/**
 * Which workflow types exist, from config('hq.workflows.definitions'). Empty in production: a type
 * that is not listed cannot be started, so this release starts nothing.
 */
final class WorkflowDefinitions
{
    /** @var array<string, WorkflowDefinition> */
    private array $definitions = [];

    /** @param  list<class-string<WorkflowDefinition>>  $classes */
    public function __construct(array $classes = [])
    {
        foreach ($classes as $class) {
            $this->register($class);
        }
    }

    /** @param  class-string<WorkflowDefinition>  $class */
    public function register(string $class): void
    {
        $definition = app($class);
        if (! $definition instanceof WorkflowDefinition) {
            throw new InvalidArgumentException("{$class} does not extend ".WorkflowDefinition::class);
        }
        $this->definitions[$definition->type()] = $definition;
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function get(string $type): WorkflowDefinition
    {
        return $this->definitions[$type]
            ?? throw new InvalidArgumentException("Unknown workflow type [{$type}]. It is not listed in config hq.workflows.definitions.");
    }
}
