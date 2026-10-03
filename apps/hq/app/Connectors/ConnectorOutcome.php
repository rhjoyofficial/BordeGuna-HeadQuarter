<?php

namespace App\Connectors;

/**
 * The typed result of a resolution. Only `Resolved` names a connector; every other case is a refusal
 * with a reason, returned as data, never thrown, so the gateway can record it and an agent can be told
 * plainly rather than reading an exception message as text.
 */
enum ConnectorOutcome: string
{
    case Resolved = 'resolved';

    /** The capability is known and nothing is registered for it. The state of this release for all of them. */
    case NoConnector = 'no_connector';

    /** Connectors are registered and none can serve right now: off, out of scope, or missing a credential. */
    case Unavailable = 'unavailable';

    /** Two or more connectors at the same priority could serve and nothing says which: refused, not chosen. */
    case Ambiguous = 'ambiguous';

    /** The name is not in the catalogue, or is reserved without a contract. */
    case UnknownCapability = 'unknown_capability';
}
