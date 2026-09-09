<?php

namespace App\Platform\Contracts;

/**
 * A channel that carries a direct message to one person (Phase 3 and 7 will implement these).
 *
 * Provider-neutral on purpose: Messenger, Instagram DM, WhatsApp and SMS differ enormously in their
 * rules and not at all in what HQ wants from them. The rules that differ are expressed as
 * capabilities and consent classes, not as four unrelated services.
 *
 * ⚠ No implementation ships in Phase 2.5. The interface exists now so that consent, capability and
 * autonomy can be written against something real rather than being retrofitted to whichever
 * provider arrives first and sets the shape by accident.
 */
interface MessagingChannel extends PlatformProvider
{
    /** Capability key: send a message the customer's own message opened the door for. */
    public const SEND_SERVICE = 'send_service_message';

    /** Capability key: send an unsolicited or promotional message. */
    public const SEND_MARKETING = 'send_marketing_message';

    /** Capability key: send a pre-approved template (WhatsApp outside the 24-hour window). */
    public const SEND_TEMPLATE = 'send_template';

    /** Capability key: read inbound messages. */
    public const RECEIVE = 'receive_messages';

    /** Capability key: mark read, typing indicators and similar niceties. */
    public const PRESENCE = 'presence';
}
