<?php

namespace App\Conversation\Channels\Meta;

/**
 * A refused or failed Meta send, classified so a caller can tell one it may retry from one it must not
 * (docs/88 A point 2).
 *
 * Meta answers every Graph failure as `{"error": {"message", "type", "code", "error_subcode",
 * "fbtrace_id"}}`, and reuses codes across products (code 10 is a permission problem, and with
 * subcode 2018278 it is Messenger's closed 24-hour window). The classification is by code and subcode,
 * never by message text, and anything it does not recognise is `unknown`, not retryable.
 *
 * Codes read on 22 September 2026: the WhatsApp Cloud API error-code page (0, 3, 4, 10, 100, 190,
 * 200-299, 80007, 130429, 131000, 131008, 131009, 131016, 131026, 131047, 131049, 131050, 131051,
 * 131056, 132000-132016). Messenger 551 ("this person isn't available right now"), 613 (rate limit) and
 * 10/2018278 (outside the allowed window) are from Meta's Messenger error-code page as quoted in search
 * results, not re-read on the page itself; `docs/88-q-record.md` marks them.
 */
final readonly class MetaSendError
{
    public const RATE_LIMITED = 'rate_limited';

    public const AUTH = 'auth';

    public const PERMISSION = 'permission';

    public const OUTSIDE_WINDOW = 'outside_window';

    public const RECIPIENT_UNAVAILABLE = 'recipient_unavailable';

    /** Meta chose not to deliver (ecosystem engagement, the person stopped marketing messages). */
    public const DECLINED = 'declined';

    public const TEMPLATE = 'template';

    public const INVALID_REQUEST = 'invalid_request';

    public const PROVIDER_ERROR = 'provider_error';

    public const UNKNOWN = 'unknown';

    /** Refused inside HQ before any request: over the local send rate. */
    public const THROTTLED_LOCALLY = 'throttled_locally';

    /** Refused inside HQ before any request: the gateway opened no credential for this account. */
    public const CREDENTIAL_NOT_OPEN = 'credential_not_open';

    /** Refused inside HQ before any request: the message cannot be expressed on this channel. */
    public const UNSUPPORTED = 'unsupported';

    private const RETRYABLE = [self::RATE_LIMITED, self::PROVIDER_ERROR, self::THROTTLED_LOCALLY];

    public function __construct(
        public string $category,
        public string $message,
        public ?int $code = null,
        public ?int $subcode = null,
        public ?string $fbtraceId = null,
    ) {}

    public static function local(string $category, string $message): self
    {
        return new self($category, $message);
    }

    /** From Meta's answer: the HTTP status and the decoded body, whatever shape it took. */
    public static function fromResponse(int $httpStatus, mixed $json): self
    {
        $error = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;
        $subcode = is_numeric($error['error_subcode'] ?? null) ? (int) $error['error_subcode'] : null;
        $message = is_string($error['message'] ?? null) && $error['message'] !== '' ? $error['message'] : "HTTP {$httpStatus} from Meta";
        $trace = is_string($error['fbtrace_id'] ?? null) ? $error['fbtrace_id'] : null;

        return new self(self::classify($httpStatus, $code, $subcode), $message, $code, $subcode, $trace);
    }

    public function retryable(): bool
    {
        return in_array($this->category, self::RETRYABLE, true);
    }

    /** One line for the delivery row and the operator: the class, Meta's code, Meta's words. */
    public function describe(): string
    {
        $code = $this->code === null ? '' : ' (code '.$this->code.($this->subcode === null ? '' : '/'.$this->subcode).')';

        return "{$this->category}{$code}: {$this->message}";
    }

    private static function classify(int $httpStatus, ?int $code, ?int $subcode): string
    {
        if ($code === 10 && $subcode === 2018278) {
            return self::OUTSIDE_WINDOW;
        }

        return match (true) {
            $code === 131047 => self::OUTSIDE_WINDOW,
            in_array($code, [4, 17, 32, 613, 80007, 130429, 131056], true) => self::RATE_LIMITED,
            in_array($code, [0, 102, 190, 463, 467], true) => self::AUTH,
            $code === 3 || $code === 10 || ($code !== null && $code >= 200 && $code <= 299) => self::PERMISSION,
            in_array($code, [551, 131026], true) => self::RECIPIENT_UNAVAILABLE,
            in_array($code, [131049, 131050], true) => self::DECLINED,
            $code !== null && $code >= 132000 && $code <= 132999 => self::TEMPLATE,
            in_array($code, [100, 131008, 131009, 131051], true) => self::INVALID_REQUEST,
            in_array($code, [1, 2, 131000, 131016], true) => self::PROVIDER_ERROR,
            $httpStatus === 429 => self::RATE_LIMITED,
            $httpStatus === 401 => self::AUTH,
            $httpStatus === 403 => self::PERMISSION,
            $httpStatus >= 500 => self::PROVIDER_ERROR,
            default => self::UNKNOWN,
        };
    }
}
