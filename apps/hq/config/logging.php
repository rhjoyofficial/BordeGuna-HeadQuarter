<?php

use App\Support\Logging\RedactSecretsProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
 | Structured JSON logging. In containers everything goes to stderr (docker logs); locally to a
 | daily JSON file as well. Laravel Context (request_id, correlation_id, run_id, job_id) is
 | attached to every record automatically; RedactSecretsProcessor scrubs secret-looking keys
 | and token-like strings before any write.
 */
return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'stderr')),
            'ignore_exceptions' => false,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'formatter' => JsonFormatter::class,
            'formatter_with' => ['appendNewline' => true],
            'processors' => [PsrLogMessageProcessor::class, RedactSecretsProcessor::class],
        ],

        'daily' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => RotatingFileHandler::class,
            'with' => ['filename' => storage_path('logs/hq.log'), 'maxFiles' => 14],
            'formatter' => JsonFormatter::class,
            'formatter_with' => ['appendNewline' => true],
            'processors' => [PsrLogMessageProcessor::class, RedactSecretsProcessor::class],
        ],

        'single' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'with' => ['stream' => storage_path('logs/laravel.log')],
            'formatter' => JsonFormatter::class,
            'formatter_with' => ['appendNewline' => true],
            'processors' => [PsrLogMessageProcessor::class, RedactSecretsProcessor::class],
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => ['host' => env('PAPERTRAIL_URL'), 'port' => env('PAPERTRAIL_PORT'), 'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT')],
            'processors' => [PsrLogMessageProcessor::class, RedactSecretsProcessor::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
