<?php

namespace Didasto\RestApi\Filters;

class EndsWithFilter extends LikeFilter
{
    public function operator(): string
    {
        return 'endsWith';
    }

    public function wrap(string $value): string
    {
        return '%'.$this->escape($value);
    }

    public function description(): string
    {
        return 'ends with';
    }
}
