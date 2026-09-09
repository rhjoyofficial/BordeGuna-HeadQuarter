<?php

namespace App\Platform\Contracts;

/**
 * Where binary content actually lives (ADR-014 for the backup consequences).
 *
 * ⛔ The domain model never holds a filesystem path. `media_assets` holds a driver name and an
 * opaque key, so moving from the VPS disk to object storage is a driver, not a migration of every
 * row and every URL that was built from one.
 */
interface StorageDriver
{
    public function name(): string;

    /** Store bytes and return the opaque key they can be read back by. */
    public function put(string $key, string $contents, string $contentType): string;

    public function get(string $key): ?string;

    public function exists(string $key): bool;

    public function delete(string $key): bool;

    /** Bytes stored, or null when the driver cannot say without reading. */
    public function size(string $key): ?int;
}
