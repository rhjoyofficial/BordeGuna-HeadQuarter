<?php

namespace App\Courier;

/**
 * One vocabulary for where a parcel is, whatever the courier calls it (docs/144-courier-connectors).
 *
 * A courier's own wording is kept beside it (`raw_status`) and never discarded; this is only what HQ
 * screens, filters and alerts read. An unrecognised word is `Unknown`, never guessed into `Delivered`:
 * a parcel wrongly shown as delivered is worse than one shown as "we do not know".
 */
enum ParcelStatus: string
{
    case Created = 'created';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case OnHold = 'on_hold';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    /** Nothing more will happen to the parcel: polling stops. */
    public function terminal(): bool
    {
        return in_array($this, [self::Delivered, self::Returned, self::Cancelled], true);
    }

    /** Order of a normal journey, so a late-arriving older event cannot move a parcel backwards. */
    public function rank(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::Created => 1,
            self::PickedUp => 2,
            self::InTransit, self::OnHold => 3,
            self::OutForDelivery => 4,
            self::Delivered, self::Returned, self::Cancelled => 5,
        };
    }

    /**
     * Keyword normaliser shared by the four drivers. Order matters: the negative and compound phrases are
     * tested before the bare "deliver" they contain.
     */
    public static function fromCourierText(?string $text): self
    {
        $t = strtolower(trim((string) $text));
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? '';
        if ($t === '') {
            return self::Unknown;
        }

        $has = static function (string ...$needles) use ($t): bool {
            foreach ($needles as $needle) {
                if (str_contains($t, $needle)) {
                    return true;
                }
            }

            return false;
        };

        return match (true) {
            $has('cancel') => self::Cancelled,
            $has('return') => self::Returned,
            $has('hold', 'failed', 'unreachable', 'area change') => self::OnHold,
            $has('out for delivery', 'delivery in progress', 'assigned for delivery', 'assigned to rider', 'delivery man') => self::OutForDelivery,
            // The courier's own review step: the parcel is still moving, it is not yet final.
            $has('approval pending') => self::InTransit,
            $has('partial', 'delivered') => self::Delivered,
            $has('pickup pending', 'pending', 'created', 'requested', 'in review', 'ready for pickup') => self::Created,
            $has('picked', 'pickup') => self::PickedUp,
            $has('transit', 'sorting', 'hub', 'arrived', 'dispatched', 'ready for delivery') => self::InTransit,
            default => self::Unknown,
        };
    }
}
