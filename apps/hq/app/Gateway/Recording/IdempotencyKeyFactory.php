<?php

namespace App\Gateway\Recording;

use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\ToolRequest;

/**
 * Derives the idempotency key when the caller did not supply one.
 *
 * The distinction that makes this correct: idempotency protects against a duplicate DELIVERY of one
 * logical request, not against two genuine requests that happen to look alike. Two separate
 * operator clicks on "check integrity" are two requests and both should run; one queue message
 * delivered twice is one request and must run once.
 *
 * So the derived key includes the caller's run identity (the job run, or the correlation id). A
 * redelivery carries the same run identity and derives the same key; a second genuine call arrives
 * under a new correlation id and derives a different one. A caller that knows better says so by
 * passing an explicit key, and an explicit key always replays.
 */
final class IdempotencyKeyFactory
{
    public function derive(ToolRequest $request, ToolDescriptor $descriptor, array $args): string
    {
        if ($request->idempotencyKey !== null && $request->idempotencyKey !== '') {
            return $request->idempotencyKey;
        }

        // Only the argument paths the tool declared, so an irrelevant argument cannot change the
        // identity of the operation. An empty declaration means the whole argument set.
        $material = $descriptor->idempotencyKeyFrom === []
            ? $args
            : array_reduce(
                $descriptor->idempotencyKeyFrom,
                static function (array $carry, string $path) use ($args) {
                    $carry[$path] = data_get($args, $path);

                    return $carry;
                },
                [],
            );

        $runScope = $request->jobRunId !== null ? "job-run:{$request->jobRunId}" : "corr:{$request->correlationId}";

        return 'd:'.substr(hash('sha256', implode('|', [
            $descriptor->key,
            $descriptor->version,
            $runScope,
            $this->canonical($material),
        ])), 0, 48);
    }

    /**
     * Sorted keys, stable encoding. Two argument arrays that mean the same thing must produce the
     * same string, or the key is a coin toss and replay never fires.
     */
    private function canonical(array $data): string
    {
        $sorted = $this->sortRecursive($data);

        return (string) json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function sortRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRecursive($value);
            }
        }
        if (! array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }
}
