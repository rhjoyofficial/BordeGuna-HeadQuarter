<?php

namespace App\Platform\Contracts;

/** Voice, for Phase 7. Recording is a separate capability because consent for it is separate. */
interface TelephonyProvider extends PlatformProvider
{
    public const PLACE_CALL = 'place_call';

    public const RECEIVE_CALL = 'receive_call';

    public const RECORD = 'record_call';

    public const TRANSCRIBE = 'transcribe_call';

    public const TRANSFER = 'transfer_to_human';
}
