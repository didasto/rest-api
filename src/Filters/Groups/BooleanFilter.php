<?php

namespace Didasto\RestApi\Filters\Groups;

use Didasto\RestApi\Filters\EqualsFilter;
use Didasto\RestApi\Filters\NullFilter;

class BooleanFilter extends FilterGroup
{
    public function filters(): array
    {
        return [
            EqualsFilter::class,
            NullFilter::class,
        ];
    }

    public function type(): string
    {
        return 'boolean';
    }
}
