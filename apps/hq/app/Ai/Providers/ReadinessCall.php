<?php

namespace App\Ai\Providers;

use Illuminate\Http\Client\Response;

/**
 * One provider's tiny readiness call (docs/77 E.1, docs/81 C.7): the request lives in the driver
 * layer, where provider endpoints may be named; what the answer MEANS is `App\Ai\ProviderCheck`'s.
 */
interface ReadinessCall
{
    /** Matches `ModelProvider::key()`. */
    public function provider(): string;

    /** The model the call would be sent to, or null when the provider has no row to send it to. */
    public function modelId(): ?string;

    /** The call itself, the key opened in a vault scope and never printed; the answer untouched. */
    public function send(): Response;
}
