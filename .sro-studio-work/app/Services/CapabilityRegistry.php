<?php

namespace App\Services;

use RuntimeException;

/** Central catalogue for operations exposed by CASY's visual studios. */
final class CapabilityRegistry
{
    public function get(string $operation): array
    {
        // Operation IDs intentionally contain dots (for example item.updated),
        // so read the registry as an array instead of Laravel's dotted config
        // path resolver.
        $capability = (array) config('casy.capabilities', []);
        $capability = $capability[$operation] ?? null;
        if (! is_array($capability)) {
            throw new RuntimeException('This operation is not registered in the CASY capability registry.');
        }
        return ['operation' => $operation] + $capability;
    }

    public function can(string $operation): bool
    {
        return is_array(((array) config('casy.capabilities', []))[$operation] ?? null);
    }

    public function all(): array
    {
        return (array) config('casy.capabilities', []);
    }
}
