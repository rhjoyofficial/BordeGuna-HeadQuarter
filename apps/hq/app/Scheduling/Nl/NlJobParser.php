<?php

namespace App\Scheduling\Nl;

use App\Ai\ModelRuntime;
use App\Ai\RoutingContext;
use App\Ai\TaskType;
use App\Models\Business;
use App\Models\Store;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;

/**
 * Reads a sentence as a proposed scheduled job (docs/48 L.1): the low tier, a strict schema, the
 * registered tools and the real businesses and stores in the prompt, and nothing else.
 *
 * ⛔ It returns a draft. It never creates a job, never calls a tool and never sees a credential: the
 * validator decides whether the draft is a job, and a person confirms it before a row exists. The
 * model reaches no tool through this path; the only thing downstream of it is the scheduler's own
 * table, which runs tools through the gateway like every other job.
 */
final class NlJobParser
{
    private const MAX_OUTPUT_TOKENS = 800;

    public function __construct(
        private readonly ModelRuntime $runtime,
        private readonly ToolRegistry $tools,
    ) {}

    /** @return array{draft: JobSpecDraft, ai_run_id: int, degraded: bool} */
    public function parse(string $text, ?int $businessId, CarbonImmutable $now): array
    {
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');

        $outcome = $this->runtime->run(
            task: TaskType::NlToJobSpec,
            system: $this->system($now->setTimezone($timezone)),
            messages: [['role' => 'user', 'content' => $text]],
            context: new RoutingContext(businessId: $businessId, requiredCapabilities: ['json_schema'], inputRef: 'scheduler.nl'),
            jsonSchema: JobSpecSchema::schema(),
            maxOutputTokens: self::MAX_OUTPUT_TOKENS,
            promptVersion: JobSpecSchema::VERSION,
        );

        return [
            'draft' => JobSpecDraft::fromArray($outcome->data ?? []),
            'ai_run_id' => (int) $outcome->run->id,
            'degraded' => $outcome->degraded,
        ];
    }

    private function system(CarbonImmutable $localNow): string
    {
        $tools = [];
        foreach ($this->tools->keys() as $key) {
            $tool = $this->tools->get($key);
            $descriptor = $tool->descriptor();
            $arguments = array_keys((array) ($tool->inputSchema()['properties'] ?? []));
            $tools[] = "- {$key}: {$descriptor->title}. {$descriptor->purpose} Arguments: ".($arguments === [] ? 'none' : implode(', ', $arguments)).'.';
        }

        $businesses = Business::query()->where('is_active', true)->orderBy('name')->get(['name', 'slug'])
            ->map(fn (Business $b) => "- {$b->name} ({$b->slug})")->implode("\n");
        $stores = Store::query()->where('is_active', true)->orderBy('name')->get(['name', 'slug'])
            ->map(fn (Store $s) => "- {$s->name} ({$s->slug})")->implode("\n");

        return implode("\n", [
            'You turn one sentence from an HQ operator into a proposed scheduled job for HQ\'s scheduler.',
            'You only propose. HQ checks everything you return against its own records, and a person confirms the job before it exists.',
            '',
            'Rules:',
            '- tool_key must be exactly one of the tool keys listed below. Never invent one.',
            '- business and store must be names or slugs from the lists below, or empty. Never invent one.',
            '- If anything needed is missing or unclear (which tool, which business, when, how often), return outcome "question" with ONE short question in the operator\'s language, and leave the other fields empty.',
            '- A time that has already passed today is unclear: ask.',
            '- kind is "once" (run_at_local), "cron" (cron_expression, five fields), "interval" (interval_minutes) or "event" (event_type). Leave the fields of the other kinds empty or zero.',
            '- Times are in the job\'s timezone, written "YYYY-MM-DD HH:MM". The timezone is '.$localNow->getTimezone()->getName().' unless the operator names another.',
            '- payload_json is a JSON object with the tool\'s arguments, "{}" when it takes none.',
            '- name is a short label for the job in the operator\'s words.',
            '- The sentence may be in Bangla, Banglish or English. Relative dates ("tomorrow", "next Sunday", "আগামীকাল") are relative to the current time below.',
            '',
            'Current time: '.$localNow->format('l j F Y H:i T'),
            '',
            'Tools:',
            implode("\n", $tools),
            '',
            'Businesses:',
            $businesses !== '' ? $businesses : '- none',
            '',
            'Stores:',
            $stores !== '' ? $stores : '- none',
        ]);
    }
}
