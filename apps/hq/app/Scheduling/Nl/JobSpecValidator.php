<?php

namespace App\Scheduling\Nl;

use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Validation\ArgumentValidator;
use App\Models\ScheduledJob;
use App\Models\User;
use App\Scheduling\Enums\ApprovalState;
use App\Scheduling\NextRunCalculator;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Decides whether a proposed job exists (docs/48 L.1 to L.5). Deterministic: no model, no guess.
 *
 * Everything the model said is checked against what HQ holds: the tool is registered, the arguments
 * validate against that tool's own schema, the business and store are real rows the person may use,
 * the schedule parses and lies in the future, the timezone is real. Anything that fails, or that
 * docs/48 L.3 names as ambiguous (a day of the month some months lack, a time already passed), is
 * a question, never a silent correction.
 *
 * A job inherits its tool's risk (L.5): a job that runs an R3 or higher tool is created pending and
 * does not run until a person approves it, so a delay can never launder a gated action.
 */
final class JobSpecValidator
{
    public const MIN_INTERVAL_MINUTES = 1;

    public const MAX_INTERVAL_MINUTES = 60 * 24 * 31;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ArgumentValidator $arguments,
        private readonly EntityResolver $entities,
        private readonly NextRunCalculator $calculator,
    ) {}

    public function validate(JobSpecDraft $draft, User $user, ?CarbonImmutable $now = null): JobSpecResult
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        if ($draft->outcome === JobSpecSchema::OUTCOME_QUESTION) {
            return JobSpecResult::questions([$draft->question !== '' ? $draft->question : 'What should HQ schedule, and when?']);
        }

        $questions = [];

        $timezone = $draft->timezone !== '' ? $draft->timezone : (string) config('hq.timezone', 'Asia/Dhaka');
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $questions[] = "\"{$draft->timezone}\" is not a timezone HQ knows. Which timezone should the schedule follow?";
            $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        }

        if (! $this->tools->has($draft->toolKey)) {
            return JobSpecResult::questions(array_merge($questions, [
                $draft->toolKey === ''
                    ? 'Which of HQ\'s tools should the job run?'
                    : "HQ has no tool called \"{$draft->toolKey}\". Which of HQ's tools should the job run?",
            ]));
        }
        $tool = $this->tools->get($draft->toolKey);
        $descriptor = $tool->descriptor();

        $business = $this->entities->business($draft->business, $user);
        $store = $this->entities->store($draft->store, $business['id'], $user);
        $questions[] = $business['question'];
        $questions[] = $store['question'];

        $payload = $this->payload($draft->payloadJson, $questions);
        $schema = $tool->inputSchema();
        $properties = (array) ($schema['properties'] ?? []);
        if ($business['slug'] !== null && array_key_exists('business_slug', $properties)) {
            $payload['business_slug'] ??= $business['slug'];
        }
        if ($store['slug'] !== null) {
            if (array_key_exists('store_slug', $properties)) {
                $payload['store_slug'] ??= $store['slug'];
            } else {
                $questions[] = "\"{$descriptor->title}\" does not take a store. Should it run for the whole business instead?";
            }
        }
        $checked = $this->arguments->validate($payload, $schema);
        if (! $checked->valid) {
            $questions[] = "The arguments for \"{$descriptor->title}\" are not right: {$checked->message()} What should they be?";
        }

        $attributes = [
            'name' => $draft->name !== '' ? mb_substr($draft->name, 0, 120) : $descriptor->title,
            'kind' => $draft->kind,
            'tool_key' => $descriptor->key,
            'timezone' => $timezone,
            'payload' => $checked->valid ? $checked->normalised : $payload,
            'business_id' => $business['id'],
        ];

        $questions = array_merge($questions, $this->schedule($draft, $timezone, $now, $attributes));

        $questions = array_values(array_filter($questions));
        if ($questions !== []) {
            return JobSpecResult::questions($questions);
        }

        $gated = $descriptor->riskTier->atLeast(RiskTier::R3);
        $attributes['approval_state'] = $gated ? ApprovalState::Pending->value : ApprovalState::Approved->value;

        $probe = new ScheduledJob($attributes);
        $next = $this->calculator->next($probe, $now);

        return JobSpecResult::job($attributes, [
            'name' => $attributes['name'],
            'tool' => ['key' => $descriptor->key, 'title' => $descriptor->title, 'risk_tier' => $descriptor->riskTier->value],
            'schedule' => $this->describe($attributes),
            'timezone' => $timezone,
            'business' => $business['name'],
            'store' => $store['name'],
            'payload' => $attributes['payload'],
            'first_run_local' => $next?->setTimezone($timezone)->format('D j M Y H:i T'),
            'first_run_utc' => $next?->toIso8601String(),
            'needs_approval' => $gated,
            'approval_note' => $gated
                ? "This job runs a {$descriptor->riskTier->label()} tool, so it is created paused for approval and does not run until a person approves it."
                : 'This job runs a read-only tool and starts on schedule once you confirm.',
        ]);
    }

    /** @param list<string|null> $questions */
    private function payload(string $json, array &$questions): array
    {
        if ($json === '' || $json === '{}') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $questions[] = 'The job\'s arguments could not be read. What should the job do exactly?';

            return [];
        }

        if (! is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            $questions[] = 'The job\'s arguments could not be read. What should the job do exactly?';

            return [];
        }

        return $decoded;
    }

    /**
     * Checks the schedule and completes the attributes for its kind.
     *
     * @return list<string|null>
     */
    private function schedule(JobSpecDraft $draft, string $timezone, CarbonImmutable $now, array &$attributes): array
    {
        switch ($draft->kind) {
            case 'cron':
                try {
                    $this->calculator->validateCron($draft->cronExpression);
                } catch (InvalidArgumentException) {
                    return ['HQ could not read that as a repeating schedule. When exactly should it run?'];
                }
                $fields = preg_split('/\s+/', $draft->cronExpression) ?: [];
                if (preg_match('/(^|,)(29|30|31)(,|$)/', (string) ($fields[2] ?? '')) === 1) {
                    return ['Some months have no day '.($fields[2] ?? '').'. Should the job run on the last day of those months, or skip them?'];
                }
                $attributes['cron_expression'] = $draft->cronExpression;

                return [];

            case 'once':
                try {
                    $at = CarbonImmutable::createFromFormat('Y-m-d H:i', $draft->runAtLocal, $timezone);
                } catch (Throwable) {
                    $at = false;
                }
                if (! $at instanceof CarbonImmutable || $at->format('Y-m-d H:i') !== $draft->runAtLocal) {
                    return ['HQ could not read when the job should run. What date and time did you mean?'];
                }
                if ($at->utc()->lte($now)) {
                    return ["{$at->format('D j M H:i')} has already passed. When should it run instead?"];
                }
                $attributes['run_at'] = $at->utc();

                return [];

            case 'interval':
                if ($draft->intervalMinutes < self::MIN_INTERVAL_MINUTES || $draft->intervalMinutes > self::MAX_INTERVAL_MINUTES) {
                    return ['A repeating job runs at most once a minute and at least once a month. How often should it run?'];
                }
                $attributes['interval_seconds'] = $draft->intervalMinutes * 60;

                return [];

            case 'event':
                // The event drain fires event jobs (`EventTriggeredJobs`, docs/95 §16 NOW item 4). The
                // type is a dotted name such as `order.created`; one HQ never emits simply never fires.
                $type = trim($draft->eventType);
                if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $type) !== 1) {
                    return ['Which event should start the job? Name it the way HQ does, such as order.created or shipment.delivered.'];
                }
                $attributes['event_type'] = $type;

                return [];

            default:
                return ['When should the job run: once, on a repeating schedule, every so often, or when something happens?'];
        }
    }

    /** The schedule in words, in the job's own timezone. */
    private function describe(array $attributes): string
    {
        return match ($attributes['kind']) {
            'cron' => "on the schedule {$attributes['cron_expression']} ({$attributes['timezone']})",
            'once' => 'once, at '.CarbonImmutable::parse($attributes['run_at'])->setTimezone($attributes['timezone'])->format('D j M Y H:i T'),
            'interval' => 'every '.intdiv((int) $attributes['interval_seconds'], 60).' minute(s)',
            'event' => "whenever {$attributes['event_type']} happens",
            default => $attributes['kind'],
        };
    }
}
