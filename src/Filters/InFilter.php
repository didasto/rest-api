<?php

namespace Didasto\RestApi\Filters;

use Illuminate\Database\Eloquent\Builder;

class InFilter extends Filter
{
    public function operator(): string
    {
        return 'in';
    }

    public function parse(mixed $value): mixed
    {
        return is_array($value)
            ? array_values($value)
            : array_map('trim', explode(',', (string) $value));
    }

    public function apply(Builder $query, mixed $value): Builder
    {
        return $query->whereIn($this->column(), (array) $value);
    }

    public function schema(): array
    {
        return ['type' => 'string', 'description' => 'Comma separated list'];
    }

    public function description(): string
    {
        return 'is one of';
    }
}
