<?php

namespace Didasto\RestApi\Filters\Groups;

use Didasto\RestApi\Filters\BetweenFilter;
use Didasto\RestApi\Filters\EqualsFilter;
use Didasto\RestApi\Filters\GreaterOrEqualFilter;
use Didasto\RestApi\Filters\GreaterThanFilter;
use Didasto\RestApi\Filters\LessOrEqualFilter;
use Didasto\RestApi\Filters\LessThanFilter;
use Didasto\RestApi\Filters\NotEqualsFilter;
use Didasto\RestApi\Filters\NullFilter;

class DateFilter extends FilterGroup
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
            BetweenFilter::class,
            NullFilter::class,
        ];
    }

    public function format(): ?string
    {
        return 'date-time';
    }
}
