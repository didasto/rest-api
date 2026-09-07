<?php

namespace Didasto\RestApi\Filters\Groups;

use Didasto\RestApi\Filters\EndsWithFilter;
use Didasto\RestApi\Filters\EqualsFilter;
use Didasto\RestApi\Filters\InFilter;
use Didasto\RestApi\Filters\LikeFilter;
use Didasto\RestApi\Filters\NotEqualsFilter;
use Didasto\RestApi\Filters\NotInFilter;
use Didasto\RestApi\Filters\NullFilter;
use Didasto\RestApi\Filters\StartsWithFilter;

class StringFilter extends FilterGroup
{
    public function filters(): array
    {
        return [
            EqualsFilter::class,
            NotEqualsFilter::class,
            LikeFilter::class,
            StartsWithFilter::class,
            EndsWithFilter::class,
            InFilter::class,
            NotInFilter::class,
            NullFilter::class,
        ];
    }
}
