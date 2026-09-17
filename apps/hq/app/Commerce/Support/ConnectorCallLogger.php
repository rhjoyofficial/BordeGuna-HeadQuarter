<?php

namespace App\Commerce\Support;

use App\Commerce\Exceptions\Uncertain;
use App\Models\ConnectorCall;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records every outbound connector call (docs/25). Never logs request bodies or credentials.
 *
 * Each row carries the correlation id the gateway scoped for the tool call that made it, and the HTTP
 * status the external system answered with, on success as well as failure (docs/48 N.1, docs/78 E):
 * together they are the last two links of the trace from an operator's question to what the other
 * system said. The status comes from the HTTP client's own `ResponseReceived` event, noted while a call
 * is being recorded, so no adapter has to hand it back.
 */
final class ConnectorCallLogger
{
    /** The status of the last HTTP response received inside the call being recorded. */
    private static ?int $observedStatus = null;

    /** How many calls are being recorded right now; statuses outside a call are ignored. */
    private static int $depth = 0;

    /** Called by the HTTP client's response event; a no-op outside a recorded call. */
    public static function observe(int $status): void
    {
        if (self::$depth > 0) {
            self::$observedStatus = $status;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function call(string $connector, string $operation, callable $fn, ?string $idempotencyKey = null, ?string $requestRef = null)
    {
        $started = microtime(true);
        // Process-lifetime state in a long-running worker: saved and restored around every call, so a
        // status can never leak from one call into another.
        $outer = self::$observedStatus;
        self::$observedStatus = null;
        self::$depth++;

        try {
            $result = $fn();
            $this->record($connector, $operation, $started, 'succeeded', self::$observedStatus, null, $idempotencyKey, $requestRef);

            return $result;
        } catch (Uncertain $e) {
            $this->record($connector, $operation, $started, 'uncertain', $e->getCode() ?: self::$observedStatus, $e->getMessage(), $idempotencyKey, $requestRef);
            throw $e;
        } catch (Throwable $e) {
            $this->record($connector, $operation, $started, 'failed', $e->getCode() ?: self::$observedStatus, $e->getMessage(), $idempotencyKey, $requestRef);
            throw $e;
        } finally {
            self::$depth--;
            self::$observedStatus = $outer;
        }
    }

    private function record(string $connector, string $operation, float $started, string $status, mixed $httpStatus, ?string $error, ?string $key, ?string $ref): void
    {
        $httpStatus = is_int($httpStatus) ? $httpStatus : null;

        try {
            ConnectorCall::create([
                'connector' => $connector,
                'operation' => $operation,
                'idempotency_key' => $key,
                'correlation_id' => $this->correlationId(),
                'request_ref' => $ref,
                'response_status' => $httpStatus !== null && $httpStatus >= 100 && $httpStatus <= 599 ? $httpStatus : null,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'status' => $status,
                'error' => $error ? substr($error, 0, 2000) : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('connector_calls write failed', ['connector' => $connector, 'operation' => $operation, 'error' => $e->getMessage()]);
        }
    }

    private function correlationId(): ?string
    {
        $id = Context::get('correlation_id');

        return is_string($id) && $id !== '' ? mb_substr($id, 0, 64) : null;
    }
}
