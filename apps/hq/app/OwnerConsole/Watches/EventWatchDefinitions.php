<?php

namespace App\OwnerConsole\Watches;

/**
 * The bounded, versioned catalogue of events a watch may look for (ChatGPT ruling 31 Oct 2026, item 6: "Event
 * definitions must be bounded and versioned").
 *
 * A watch names one type below and the condition paths it may read are only those listed for that type. The watch
 * stores the version it was proposed against; when a definition changes its version is raised and the old watches
 * stop firing (a firing recorded as `definition_changed`) until a person proposes them again. A type removed from
 * this list stops every watch of it the same way.
 *
 * ⛔ Deliberately absent: every `conversation.*` event. A watch on customer messages is the shortest path to
 * autonomous customer messaging, which the ruling forbids; it would need its own ruling.
 *
 * The paths cover both payload shapes the shop sends (the full order under `order`, or flat status events). They are
 * HQ's default, not a ruling: a path that is not listed is refused at proposal time.
 */
final class EventWatchDefinitions
{
    private const ORDER_PATHS = [
        'total', 'grand_total', 'status', 'payment_method', 'payment_status',
        'order.total', 'order.grand_total', 'order.status', 'order.payment_method', 'order.payment_status', '_store',
    ];

    /** @var array<string, array{version: int, paths: list<string>}> */
    public const DEFINITIONS = [
        'order.created' => ['version' => 1, 'paths' => self::ORDER_PATHS],
        'order.updated' => ['version' => 1, 'paths' => self::ORDER_PATHS],
        'order.status_changed' => ['version' => 1, 'paths' => ['from', 'to', 'old_status', 'new_status', 'order_number', '_store']],
        'order.payment_updated' => ['version' => 1, 'paths' => ['to', 'payment_method', 'order_status', 'order_number', '_store']],
        'payment.completed' => ['version' => 1, 'paths' => ['method', 'amount', 'order_number', '_store']],
        'shipment.status_updated' => ['version' => 1, 'paths' => ['to', 'new_status', 'order_number', '_store']],
        'shipment.delivered' => ['version' => 1, 'paths' => ['order_number', '_store']],
        'customer.registered' => ['version' => 1, 'paths' => ['_store']],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function version(string $type): ?int
    {
        return self::DEFINITIONS[$type]['version'] ?? null;
    }

    public static function allowsPath(string $type, string $path): bool
    {
        return in_array($path, self::DEFINITIONS[$type]['paths'] ?? [], true);
    }
}
