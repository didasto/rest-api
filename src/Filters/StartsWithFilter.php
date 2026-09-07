<?php

namespace Didasto\RestApi\Filters;

class StartsWithFilter extends LikeFilter
{
    public function operator(): string
    {
        return 'startsWith';
    }

    public function wrap(string $value): string
    {
        return $this->escape($value).'%';
    }

    public function description(): string
    {
        return 'beginnt mit';
    }
}
