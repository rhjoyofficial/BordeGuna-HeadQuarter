<?php

namespace App\Observe\Alerts;

use App\Models\Alert;
use App\Support\Audit\Auditor;
use App\Support\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Turns what the rules find into alert rows and messages (docs/48 N.5, docs/78 B.2).
 *
 * A condition first seen opens an alert: audited, and sent once when HQ is the one that pages it. A
 * condition seen again only counts. A condition gone for `resolve_after_clear_checks` consecutive
 * checks resolves its alert, audited, with a message when HQ sent the opening one. An alert HQ pages
 * that is still open after `renotify_after_hours`, or that got more severe, is sent once more.
 *
 * A rule that throws or cannot tell is skipped for this check: its open alerts are neither counted nor
 * cleared, because silence from a broken check is not evidence that the condition went away.
 */
final class AlertEngine
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly Auditor $auditor,
    ) {}

    /** @return list<AlertRule> */
    public function rules(): array
    {
        return array_map(function (string $class) {
            $rule = app($class);
            if (! $rule instanceof AlertRule) {
                throw new InvalidArgumentException("[{$class}] is listed in hq.alerts.rules but is not an AlertRule.");
            }

            return $rule;
        }, (array) config('hq.alerts.rules', []));
    }

    /**
     * @param  list<string>  $only  rule keys to check; every rule when empty
     * @return array{opened: list<string>, still_open: list<string>, cleared: list<string>, resolved: list<string>, unknown: list<string>, failed: list<string>}
     */
    public function check(?CarbonImmutable $now = null, array $only = []): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $summary = ['opened' => [], 'still_open' => [], 'cleared' => [], 'resolved' => [], 'unknown' => [], 'failed' => []];

        foreach ($this->rules() as $rule) {
            if ($only !== [] && ! in_array($rule->key(), $only, true)) {
                continue;
            }

            try {
                $conditions = $rule->evaluate($now);
            } catch (Throwable $e) {
                // The class only: a message could carry a connection string or a query.
                Log::warning('alert rule failed', ['rule' => $rule->key(), 'exception' => get_class($e)]);
                $summary['failed'][] = $rule->key();

                continue;
            }

            if ($conditions === null) {
                $summary['unknown'][] = $rule->key();

                continue;
            }

            $seen = [];
            foreach ($conditions as $condition) {
                $fingerprint = $this->fingerprint($rule, $condition);
                $seen[] = $fingerprint;
                $opened = $this->raise($rule, $condition, $fingerprint, $now);
                $summary[$opened ? 'opened' : 'still_open'][] = $fingerprint;
            }

            $gone = Alert::open()->where('rule', $rule->key())->whereNotIn('fingerprint', $seen)->get();
            foreach ($gone as $alert) {
                $summary[$this->clear($alert, $now) ? 'resolved' : 'cleared'][] = $alert->fingerprint;
            }
        }

        return $summary;
    }

    private function fingerprint(AlertRule $rule, AlertCondition $condition): string
    {
        return mb_substr($rule->key().':'.$condition->subjectKey, 0, 191);
    }

    /** @return bool true when this check opened the alert */
    private function raise(AlertRule $rule, AlertCondition $condition, string $fingerprint, CarbonImmutable $now): bool
    {
        $open = Alert::open()->where('fingerprint', $fingerprint)->first();

        if ($open === null) {
            try {
                $open = Alert::create([
                    'rule' => $rule->key(),
                    'fingerprint' => $fingerprint,
                    'severity' => $condition->severity,
                    'subject' => mb_substr($condition->subject, 0, 255),
                    'body' => $condition->body,
                    'context' => $condition->context ?: null,
                    'paged_by' => $rule->pagedBy(),
                    'occurrences' => 1,
                    'clear_checks' => 0,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent check opened it a moment ago; count this sighting on that row.
                $this->refresh(Alert::open()->where('fingerprint', $fingerprint)->firstOrFail(), $condition, $now);

                return false;
            }

            $this->auditor->recordSystem('alert.opened', $open, after: [
                'rule' => $open->rule,
                'fingerprint' => $open->fingerprint,
                'severity' => $open->severity,
                'subject' => $open->subject,
                'paged_by' => $open->paged_by,
            ] + ($open->context ?? []));

            if ($open->pagedByHq()) {
                $this->notifier->alertRaised($open);
                $open->forceFill(['notified_at' => $now])->save();
            }

            return true;
        }

        $this->refresh($open, $condition, $now);

        return false;
    }

    /** Count another sighting; send it again when it got worse or has been open a long time. */
    private function refresh(Alert $open, AlertCondition $condition, CarbonImmutable $now): void
    {
        $worse = AlertCondition::rank($condition->severity) > AlertCondition::rank($open->severity);
        $renotifyAfter = max(1, (int) config('hq.alerts.renotify_after_hours', 24));
        $stale = $open->notified_at !== null && $open->notified_at->lte($now->subHours($renotifyAfter));

        $open->forceFill([
            'severity' => $condition->severity,
            'subject' => mb_substr($condition->subject, 0, 255),
            'body' => $condition->body,
            'context' => $condition->context ?: null,
            'occurrences' => $open->occurrences + 1,
            'clear_checks' => 0,
            'last_seen_at' => $now,
        ])->save();

        if ($open->pagedByHq() && ($worse || $stale)) {
            $this->notifier->alertStillOpen($open, $worse);
            $open->forceFill(['notified_at' => $now])->save();
        }
    }

    /** @return bool true when this check resolved the alert */
    private function clear(Alert $alert, CarbonImmutable $now): bool
    {
        $checks = $alert->clear_checks + 1;
        $needed = max(1, (int) config('hq.alerts.resolve_after_clear_checks', 2));

        if ($checks < $needed) {
            $alert->forceFill(['clear_checks' => $checks])->save();

            return false;
        }

        $alert->forceFill(['clear_checks' => $checks, 'resolved_at' => $now])->save();

        $this->auditor->recordSystem('alert.resolved', $alert, after: [
            'rule' => $alert->rule,
            'fingerprint' => $alert->fingerprint,
            'severity' => $alert->severity,
            'subject' => $alert->subject,
            'occurrences' => $alert->occurrences,
            'open_minutes' => (int) $alert->first_seen_at->diffInMinutes($now),
        ]);

        if ($alert->pagedByHq() && $alert->notified_at !== null) {
            $this->notifier->alertResolved($alert);
        }

        return true;
    }
}
