<?php

namespace Didasto\RestApi\Filters;

use Illuminate\Database\Eloquent\Builder;

class NotInFilter extends InFilter
{
    public function operator(): string
    {
        return 'notIn';
    }

    public function apply(Builder $query, mixed $value): Builder
    {
        return $query->whereNotIn($this->column(), (array) $value);
    }

    public function description(): string
    {
        return 'nicht in Liste';
    }
}
