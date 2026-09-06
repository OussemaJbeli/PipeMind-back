<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Bridges a PHP float array and pgvector's text representation.
 *
 * pgvector accepts `[0.1,0.2,...]` — JSON-shaped but not JSON, and notably
 * without spaces. Casting here rather than at each call site means no query has
 * to remember the format.
 *
 * @implements CastsAttributes<array<int,float>|null, array<int,float>|null>
 */
class Vector implements CastsAttributes
{
    /** @return array<int,float>|null */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_map('floatval', $decoded) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return '['.implode(',', array_map(
            static fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.') ?: '0',
            (array) $value,
        )).']';
    }
}
