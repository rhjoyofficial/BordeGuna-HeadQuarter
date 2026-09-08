<?php

namespace Tests\Support\Ai;

/**
 * A second provider, so cross-provider fallback can be tested.
 *
 * Fallback preferring a DIFFERENT provider is the whole point of ADR-019's outage story, and it
 * cannot be tested with only one provider registered.
 */
final class SecondFixtureProvider extends FixtureProvider
{
    public const KEY = 'fixture2';
}
