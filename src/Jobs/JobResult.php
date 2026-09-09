<?php

namespace Didasto\RestApi\Jobs;

/**
 * The result of a run. Every field is nullable, because nothing is known
 * when the run starts and individual jobs are allowed to fail.
 *
 * Each job writes its own part; the next one reads whatever is there.
 *
 *     class ProductSearchResult extends JobResult
 *     {
 *         public ?string $asin = null;
 *         public ?string $gtin = null;
 *         public ?int $productId = null;
 *     }
 */
abstract class JobResult extends JobPayload
{
    /** True when all named fields are set. Without arguments: when anything is set. */
    public function has(string ...$fields): bool
    {
        if ($fields === []) {
            return array_filter($this->toArray(), fn ($value) => $value !== null) !== [];
        }

        foreach ($fields as $field) {
            if (($this->{$field} ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    /** True when at least one of the named fields is set. */
    public function hasAny(string ...$fields): bool
    {
        foreach ($fields as $field) {
            if (($this->{$field} ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
