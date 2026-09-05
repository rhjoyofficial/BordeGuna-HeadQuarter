<?php

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Last line of defence: scrubs secret-looking keys from log context and masks token-like
 * strings in messages before anything is written. Applied to every channel via config/logging.php.
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    private const KEYS = ['password', 'password_confirmation', 'secret', 'client_secret', 'token', 'access_token', 'refresh_token', 'api_key', 'apikey', 'authorization', 'cookie', 'set-cookie', 'payload', 'two_factor_secret', 'two_factor_recovery_codes', 'app_key', 'db_password', 'redis_password'];

    private const PATTERNS = [
        '/(sk-[A-Za-z0-9_-]{8,})/' => 'sk-[redacted]',
        '/(EAA[A-Za-z0-9]{20,})/' => 'EAA[redacted]',
        '/(Bearer\s+)[A-Za-z0-9._-]{16,}/i' => '$1[redacted]',
        '/(base64:)[A-Za-z0-9+\/=]{20,}/' => '$1[redacted]',
        '/(\d{6,}:[A-Za-z0-9_-]{30,})/' => '[redacted-bot-token]',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrub($record->message),
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::KEYS, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }

    private function scrub(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }
}
