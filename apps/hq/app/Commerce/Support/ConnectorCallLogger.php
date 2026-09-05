<?php

namespace App\Commerce\Support;

use App\Commerce\Exceptions\Uncertain;
use App\Models\ConnectorCall;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Records every outbound connector call (docs/25). Never logs request bodies or credentials. */
final class ConnectorCallLogger
{
    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function call(string $connector, string $operation, callable $fn, ?string $idempotencyKey = null, ?string $requestRef = null)
    {
        $started = microtime(true);
        try {
            $result = $fn();
            $this->record($connector, $operation, $started, 'succeeded', null, null, $idempotencyKey, $requestRef);

            return $result;
        } catch (Uncertain $e) {
            $this->record($connector, $operation, $started, 'uncertain', $e->getCode() ?: null, $e->getMessage(), $idempotencyKey, $requestRef);
            throw $e;
        } catch (Throwable $e) {
            $this->record($connector, $operation, $started, 'failed', $e->getCode() ?: null, $e->getMessage(), $idempotencyKey, $requestRef);
            throw $e;
        }
    }

    private function record(string $connector, string $operation, float $started, string $status, ?int $httpStatus, ?string $error, ?string $key, ?string $ref): void
    {
        try {
            ConnectorCall::create([
                'connector' => $connector,
                'operation' => $operation,
                'idempotency_key' => $key,
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
}
