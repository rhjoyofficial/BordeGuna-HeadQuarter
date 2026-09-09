<?php

namespace App\Gateway\Descriptor;

/**
 * Which channel a tool acts on (ADR-026).
 *
 * Sending on WhatsApp and sending on SMS are not the same action with a different transport. They
 * have different consent classes, different platform capabilities, different costs and, in
 * WhatsApp's case, a 24-hour service window outside which only pre-approved templates may be sent.
 * Without this field those become either one tool that hides the difference or two unrelated tools
 * that share nothing.
 *
 * `Internal` is the honest default: everything registered today acts inside HQ or against a system
 * of record, and none of it reaches a person on a channel.
 */
enum Channel: string
{
    case Internal = 'internal';
    case Messenger = 'messenger';
    case Instagram = 'instagram';
    case Whatsapp = 'whatsapp';
    case Sms = 'sms';
    case Email = 'email';
    case Voice = 'voice';
    case WebChat = 'web_chat';
    case Comment = 'comment';

    /** Whether reaching a person on this channel needs a consent class checked first. */
    public function isOutbound(): bool
    {
        return $this !== self::Internal;
    }

    /** The `customer_identities.channel` value this maps to, where one exists. */
    public function identityChannel(): ?string
    {
        return match ($this) {
            self::Messenger => 'messenger_psid',
            self::Instagram => 'instagram_igsid',
            self::Whatsapp => 'whatsapp',
            self::Sms, self::Voice => 'phone',
            self::Email => 'email',
            default => null,
        };
    }
}
