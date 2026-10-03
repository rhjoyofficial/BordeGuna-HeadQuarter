<?php

/*
 | The ConnectorRegistry (docs/97 §6 D2, docs/98): which provider serves which capability.
 |
 | ⛔ EMPTY in this release, and that is the correct state: no new provider is activated. Every NEW
 | external adapter enters here and nowhere else (a structural test holds it); the five legacy shapes
 | are reported by read-only bridges until their own migration step.
 |
 | One row per connector:
 |
 |   [
 |       'capability' => 'voice.transcribe',          // a name from App\Connectors\Capabilities
 |       'provider' => 'google_chirp',                 // lower-case key, unique per capability
 |       'adapter' => SomeAdapter::class,              // implements the capability's contract
 |       'transport' => 'direct_api',                  // direct_api | mcp | n8n | internal
 |       'credentials' => [                            // integration_credentials kinds, never values
 |           ['kind' => 'google_speech_access_token', 'account_ref_from' => '=hq'],
 |       ],                                            // account_ref_from: =literal | business.slug | brand.slug | platform_account.ref
 |       'priority' => 10,                             // lower is tried first; a tie at the top is refused
 |       'enabled_by' => 'flag:connectors.voice.google', // flag:<feature flag> | config:<config key>; never on by default
 |       'fallback' => false,                          // a fallback is tried only when no primary is ready
 |       'businesses' => null,                         // optional: business slugs served; null = any
 |       'platform' => null,                           // optional: a platform_accounts.platform
 |   ],
 */

return [
    'connectors' => [],
];
