<?php

namespace Didasto\RestApi\Filters;

use Illuminate\Database\Eloquent\Builder;

class LessOrEqualFilter extends Filter
{
    public function operator(): string
    {
        return 'lte';
    }

    public function apply(Builder $query, mixed $value): Builder
    {
        return $query->where($this->column(), '<=', $value);
    }

    public function description(): string
    {
        return 'less than or equal to';
    }
}
