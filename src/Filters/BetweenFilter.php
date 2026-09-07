<?php

namespace Didasto\RestApi\Filters;

use Illuminate\Database\Eloquent\Builder;

class BetweenFilter extends Filter
{
    public function operator(): string
    {
        return 'between';
    }

    public function parse(mixed $value): mixed
    {
        $parts = is_array($value) ? array_values($value) : array_map('trim', explode(',', (string) $value));

        return array_slice($parts, 0, 2);
    }

    public function apply(Builder $query, mixed $value): Builder
    {
        $range = (array) $value;

        if (count($range) !== 2) {
            return $query;
        }

        return $query->whereBetween($this->column(), $range);
    }

    public function schema(): array
    {
        return ['type' => 'string', 'description' => 'Zwei Werte, kommagetrennt'];
    }

    public function description(): string
    {
        return 'zwischen zwei Werten';
    }
}
