<?php

namespace Didasto\RestApi\Filters;

use Illuminate\Database\Eloquent\Builder;

class NullFilter extends Filter
{
    public function operator(): string
    {
        return 'null';
    }

    public function parse(mixed $value): mixed
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public function apply(Builder $query, mixed $value): Builder
    {
        return $value
            ? $query->whereNull($this->column())
            : $query->whereNotNull($this->column());
    }

    public function schema(): array
    {
        return ['type' => 'boolean'];
    }

    public function description(): string
    {
        return 'is empty (true) or filled (false)';
    }
}
