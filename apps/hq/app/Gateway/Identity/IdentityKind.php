<?php

namespace App\Gateway\Identity;

/**
 * The four identity kinds (docs/48 E.1). Keeping them apart is not pedantry: most authorization
 * accidents in a system like this come from letting two of them blur together.
 *
 * The one worth saying out loud is Credential. A credential is who HQ IS at an external provider.
 * It grants nothing inside HQ. Holding the LaLaDia admin token does not mean a caller may cancel an
 * order; it means that IF the caller is permitted to cancel one, this is the token the connector
 * will use. Systems that forget this end up read-only by design and read-write in practice, because
 * the token could always do more than the design intended.
 */
enum IdentityKind: string
{
    case Human = 'human';
    case Agent = 'agent';
    case Service = 'service';
    case Credential = 'credential';
}
