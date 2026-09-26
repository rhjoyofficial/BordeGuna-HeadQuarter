<?php

namespace App\Research;

use Closure;

/**
 * Refuses a host that is, or resolves to, somewhere inside a network (docs/85 C.2).
 *
 * ⛔ A search result is third-party text. Without this, a result pointing at a link-local metadata
 * address, the VPS itself or a container name would be fetched by HQ from inside its own network,
 * which is a request forgery the research engine would be performing on the attacker's behalf. So:
 * no IP literals, no single-label or internal names, and a host whose addresses include any private,
 * loopback, link-local or reserved range is refused. A name that does not resolve is refused too.
 */
final class AddressGuard
{
    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver  host => its IPv4/IPv6 addresses */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $v4 = @gethostbynamel($host) ?: [];
            $v6 = [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $v6[] = (string) $record['ipv6'];
                }
            }

            return array_values(array_merge($v4, $v6));
        };
    }

    public function refusal(string $host): ?string
    {
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return "[{$host}] is an address, not a name; research fetches only named public hosts.";
        }

        if (! str_contains($host, '.') || preg_match('/\.(local|internal|localhost|lan|home|corp|intranet)$/', $host) === 1 || $host === 'localhost') {
            return "[{$host}] is not a public host name.";
        }

        $addresses = ($this->resolver)($host);
        if ($addresses === []) {
            return "[{$host}] does not resolve; nothing is fetched from a name HQ cannot place.";
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return "[{$host}] resolves to a private or reserved address ({$address}); research never reaches inside a network.";
            }
        }

        return null;
    }
}
