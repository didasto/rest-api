<?php

namespace Didasto\RestApi\Filters;

use Illuminate\Database\Eloquent\Builder;

class LessThanFilter extends Filter
{
    public function operator(): string
    {
        return 'lt';
    }

    public function apply(Builder $query, mixed $value): Builder
    {
        return $query->where($this->column(), '<', $value);
    }

    public function description(): string
    {
        return 'less than';
    }
}
