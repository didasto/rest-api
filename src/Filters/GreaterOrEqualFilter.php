<?php

namespace Didasto\RestApi\Filters;

use Illuminate\Database\Eloquent\Builder;

class GreaterOrEqualFilter extends Filter
{
    public function operator(): string
    {
        return 'gte';
    }

    public function apply(Builder $query, mixed $value): Builder
    {
        return $query->where($this->column(), '>=', $value);
    }

    public function description(): string
    {
        return 'greater than or equal to';
    }
}
