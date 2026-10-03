<?php

namespace App\Connectors\Legacy;

use App\Connectors\Transport;

/**
 * A provider one of the five legacy shapes serves today, described in the registry's terms so
 * readiness can show it beside the registered connectors. Reported, never resolved: the legacy shape
 * still makes the decision until its own migration step (docs/98 §5).
 */
final readonly class LegacyConnector
{
    /**
     * @param  class-string  $adapter
     * @param  list<string>  $credentials  `kind <- account_ref_from`, names only
     * @param  class-string  $shape  the legacy class that resolves it
     */
    public function __construct(
        public string $capability,
        public string $provider,
        public string $adapter,
        public Transport $transport,
        public array $credentials,
        public string $shape,
        public string $note,
    ) {}
}
