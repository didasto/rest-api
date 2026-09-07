<?php

namespace Didasto\RestApi\Filters\Groups;

use Didasto\RestApi\Filters\EqualsFilter;
use Didasto\RestApi\Filters\GreaterOrEqualFilter;
use Didasto\RestApi\Filters\GreaterThanFilter;
use Didasto\RestApi\Filters\InFilter;
use Didasto\RestApi\Filters\LessOrEqualFilter;
use Didasto\RestApi\Filters\LessThanFilter;
use Didasto\RestApi\Filters\NotEqualsFilter;
use Didasto\RestApi\Filters\NotInFilter;
use Didasto\RestApi\Filters\NullFilter;

class IdFilter extends FilterGroup
{
    public function filters(): array
    {
        return [
            EqualsFilter::class,
            NotEqualsFilter::class,
            LessThanFilter::class,
            LessOrEqualFilter::class,
            GreaterThanFilter::class,
            GreaterOrEqualFilter::class,
            InFilter::class,
            NotInFilter::class,
            NullFilter::class,
        ];
    }

    public function type(): string
    {
        return 'integer';
    }
}
