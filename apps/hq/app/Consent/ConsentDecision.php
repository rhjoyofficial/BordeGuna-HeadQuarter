<?php

namespace App\Consent;

use App\Models\ConsentEvent;

/**
 * Whether contacting this person, on this channel, for this purpose, was permitted at a given
 * instant, and which recorded event says so.
 *
 * The event id is the whole point. "The system said no" is not an answer anybody can act on months
 * later; "event 4471, a withdrawal recorded on 3 March with the customer's own message as
 * evidence" is. docs/108 adds the refusal CLASS beside it, so the reason can be counted, not parsed.
 */
final readonly class ConsentDecision
{
    private function __construct(
        public bool $permitted,
        public string $reason,
        public ?int $eventId = null,
        public ?ConsentState $state = null,
        public ?ConsentRefusal $refusal = null,
    ) {}

    /** The tool declares no consent requirement, so there is nothing to check. */
    public static function notRequired(): self
    {
        return new self(true, 'this tool declares no consent requirement');
    }

    public static function permit(ConsentEvent $event): self
    {
        return new self(true, 'granted by consent event #'.$event->id, $event->id, ConsentState::Granted);
    }

    public static function refuse(string $reason, ?ConsentEvent $event = null, ?ConsentState $state = null, ?ConsentRefusal $refusal = null): self
    {
        $refusal ??= match ($state) {
            ConsentState::DoNotContact => ConsentRefusal::DoNotContact,
            ConsentState::Withdrawn => ConsentRefusal::Withdrawn,
            ConsentState::Expired => ConsentRefusal::Expired,
            default => ConsentRefusal::NotRecorded,
        };

        return new self(false, $reason, $event?->id, $state, $refusal);
    }

    /** No event at all. Deliberately its own reason, because "never asked" is not "said no". */
    public static function unrecorded(string $reason, ConsentRefusal $refusal = ConsentRefusal::NotRecorded): self
    {
        return new self(false, $reason, refusal: $refusal);
    }

    public function toArray(): array
    {
        return [
            'permitted' => $this->permitted,
            'reason' => $this->reason,
            'consent_event_id' => $this->eventId,
            'state' => $this->state?->value,
            'refusal' => $this->refusal?->value,
        ];
    }
}
