<?php

namespace App\Scheduling\Nl;

/**
 * What the model proposed, before anything checked it. Every field is the model's words, untrusted
 * until `JobSpecValidator` has resolved and validated it.
 */
final readonly class JobSpecDraft
{
    public function __construct(
        public string $outcome,
        public string $question,
        public string $name,
        public string $kind,
        public string $toolKey,
        public string $cronExpression,
        public string $runAtLocal,
        public int $intervalMinutes,
        public string $eventType,
        public string $timezone,
        public string $business,
        public string $store,
        public string $payloadJson,
    ) {}

    /** @param array<string, mixed> $data an object that already passed `JobSpecSchema` */
    public static function fromArray(array $data): self
    {
        return new self(
            outcome: (string) ($data['outcome'] ?? ''),
            question: trim((string) ($data['question'] ?? '')),
            name: trim((string) ($data['name'] ?? '')),
            kind: (string) ($data['kind'] ?? 'none'),
            toolKey: trim((string) ($data['tool_key'] ?? '')),
            cronExpression: trim((string) ($data['cron_expression'] ?? '')),
            runAtLocal: trim((string) ($data['run_at_local'] ?? '')),
            intervalMinutes: (int) ($data['interval_minutes'] ?? 0),
            eventType: trim((string) ($data['event_type'] ?? '')),
            timezone: trim((string) ($data['timezone'] ?? '')),
            business: trim((string) ($data['business'] ?? '')),
            store: trim((string) ($data['store'] ?? '')),
            payloadJson: trim((string) ($data['payload_json'] ?? '')),
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
