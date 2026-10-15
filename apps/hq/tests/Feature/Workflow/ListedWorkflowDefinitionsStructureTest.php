<?php

use App\FollowUps\Workflow\FollowUpWorkflow;
use App\Workflow\Step;
use App\Workflow\WorkflowDefinition;
use App\Workflow\WorkflowDefinitions;
use App\Workflow\WorkflowState;

/*
 | docs/120 item 17 (S4 Q10) and item 8 (S4 Q1), docs/123 section 8: the inert pin, structural.
 |
 | The weak pin compared `hq.workflows.definitions` to a list of class names. This file instead proves the
 | invariant over the DEFINITIONS THEMSELVES, for every definition production would list (the config file as
 | shipped AND the live catalogue the engine resolves): no listed definition has a tool step, by what its
 | steps() returns and by what its source (and every parent's) declares. A negative control proves the
 | checker catches a tool step, so a green run is evidence and not an empty loop. No name list appears here:
 | listing a new type passes if, and only if, it has no tool step.
 |
 | Item 8: `followup.await_person` only waits and resumes through a person. Its resuming signal is sent
 | from one place, the follow-up service, as the deciding person.
 */

/** @return list<string> the reasons this definition is not inert; empty when it has no tool step */
function lwdProblems(WorkflowDefinition $definition): array
{
    $problems = [];
    $steps = $definition->steps();
    if ($steps === []) {
        $problems[] = 'declares no steps';
    }
    foreach ($steps as $i => $step) {
        if (! $step instanceof Step) {
            $problems[] = "step {$i} is not a Step";

            continue;
        }
        if (! in_array($step->kind, [Step::SLEEP, Step::SIGNAL], true)) {
            $problems[] = "step [{$step->name}] is a [{$step->kind}] step";
        }
        if ($step->toolKey !== null || $step->args !== null) {
            $problems[] = "step [{$step->name}] names a tool";
        }
        if ($step->maxAttempts !== null) {
            $problems[] = "step [{$step->name}] carries tool retries";
        }
    }

    // The source of the class and of every parent up to the base, comments stripped: no tool step can be
    // added by a parent, a trait or a conditional branch steps() did not take with an empty input.
    // (An anonymous class has no source of its own to read; production lists class names, never one.)
    for ($class = new ReflectionClass($definition); $class !== false && ! $class->isAnonymous() && $class->getName() !== WorkflowDefinition::class; $class = $class->getParentClass()) {
        $sources = [$class->getFileName()];
        foreach ($class->getTraits() as $trait) {
            $sources[] = $trait->getFileName();
        }
        foreach (array_filter($sources) as $file) {
            foreach (lwdSourceProblems((string) file_get_contents($file)) as $found) {
                $problems[] = basename($file)." contains [{$found}]";
            }
        }
    }

    return $problems;
}

/** @return list<string> what in this PHP source (comments stripped) could declare or reach a tool */
function lwdSourceProblems(string $php): array
{
    $code = implode('', array_map(
        static fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
        token_get_all($php),
    ));
    preg_match_all('/Step::tool\s*\(|Step::TOOL\b|->execute\s*\(|ToolGateway|App\\\\Tools\\\\/', $code, $m);

    return $m[0];
}

/** @return array<string, WorkflowDefinition> every definition production would list: shipped config and live catalogue */
function lwdListed(): array
{
    $shipped = (require config_path('hq.php'))['workflows']['definitions'];
    $listed = [];
    foreach ($shipped as $class) {
        $definition = new $class;
        $listed['config:'.$definition->type()] = $definition;
    }
    foreach (app(WorkflowDefinitions::class)->all() as $type => $definition) {
        $listed['catalogue:'.$type] = $definition;
    }

    return $listed;
}

it('holds that no listed workflow definition has a tool step, tested on the definitions themselves', function () {
    $listed = lwdListed();
    expect($listed)->not->toBeEmpty();

    $offenders = [];
    foreach ($listed as $where => $definition) {
        foreach (lwdProblems($definition) as $problem) {
            $offenders[] = "{$where}: {$problem}";
        }
    }

    expect($offenders)->toBe([]);

    // The shipped config and the live catalogue list the same types: nothing is registered on the side.
    $types = fn (string $prefix) => collect(array_keys($listed))->filter(fn ($k) => str_starts_with($k, $prefix))
        ->map(fn ($k) => substr($k, strlen($prefix)))->sort()->values()->all();
    expect($types('catalogue:'))->toBe($types('config:'));
});

it('catches a tool step when there is one (negative control)', function () {
    $withTool = new class extends WorkflowDefinition
    {
        public function type(): string
        {
            return 'test.with_tool';
        }

        public function steps(): array
        {
            return [Step::sleep('wait', 0), Step::tool('act', 'messaging.send_reply', fn (WorkflowState $s) => [])];
        }
    };
    $sleepOnly = new class extends WorkflowDefinition
    {
        public function type(): string
        {
            return 'test.sleep_only';
        }

        public function steps(): array
        {
            return [Step::sleep('wait', 0)];
        }
    };

    expect(lwdProblems($withTool))->not->toBeEmpty()
        ->and(implode(' ', lwdProblems($withTool)))->toContain('[act] is a [tool] step')->toContain('[act] names a tool')
        ->and(lwdProblems($sleepOnly))->toBe([]);

    // The source check catches a declared tool step even in a branch steps() did not take, and ignores prose.
    $branchy = '<?php final class X { public function steps(): array { return $this->on ? [Step::tool(\'a\', \'k\', fn () => [])] : []; } }';
    expect(lwdSourceProblems($branchy))->toBe(['Step::tool('])
        ->and(lwdSourceProblems('<?php // never add Step::tool( here'."\n".'final class Y {}'))->toBe([]);
});

it('resumes followup.await_person only through a person: its signal is sent from the follow-up service alone', function () {
    $definition = new FollowUpWorkflow;
    expect(lwdProblems($definition))->toBe([])
        ->and($definition->listensTo())->toBe([])
        ->and(collect($definition->steps())->where('kind', Step::SIGNAL)->pluck('signal')->all())->toBe([FollowUpWorkflow::SIGNAL_PERSON_DECIDED]);

    $senders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with($file->getFilename(), '.php') || str_ends_with($file->getPathname(), 'FollowUpWorkflow.php')) {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (str_contains($code, 'SIGNAL_PERSON_DECIDED') || str_contains($code, 'followup.person_decided')) {
            $senders[] = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
        }
    }
    expect($senders)->toBe(['FollowUps/FollowUpProposalService.php']);

    // And the service sends it only from completeFollowUp, after the deciding person was checked.
    $service = file_get_contents(app_path('FollowUps/FollowUpProposalService.php'));
    expect(substr_count($service, 'SIGNAL_PERSON_DECIDED'))->toBe(1)
        ->and(preg_match('/function completeFollowUp\(FollowUp \$followUp, User \$by, string \$note\): FollowUp\s*\{\s*\$this->decider\(\$by, \$followUp\);/', $service))->toBe(1);
});
