<?php

namespace Didasto\RestApi\Filters;

use Illuminate\Database\Eloquent\Builder;

class LikeFilter extends Filter
{
    public function operator(): string
    {
        return 'like';
    }

    public function wrap(string $value): string
    {
        return '%'.$this->escape($value).'%';
    }

    /** % und _ im Suchbegriff sollen keine Platzhalter sein. */
    public function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    public function apply(Builder $query, mixed $value): Builder
    {
        return $query->where($this->column(), 'like', $this->wrap((string) $value));
    }

    public function description(): string
    {
        return 'enthaelt';
    }
}
