<?php

namespace Didasto\RestApi\Filters;

use Illuminate\Database\Eloquent\Builder;

class NotEqualsFilter extends Filter
{
    public function operator(): string
    {
        return 'ne';
    }

    public function apply(Builder $query, mixed $value): Builder
    {
        return $query->where($this->column(), '!=', $value);
    }

    public function description(): string
    {
        return 'ist ungleich';
    }
}
