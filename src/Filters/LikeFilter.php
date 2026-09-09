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

    /**
     * A % or _ typed by the caller is part of the search term, not a
     * wildcard. Without escaping, a search for "100%" would match every
     * row starting with "100".
     */
    public function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * The ESCAPE clause is spelled out on purpose. MySQL happens to treat
     * a backslash as the escape character by default, SQLite and Postgres
     * do not - without it the escaping above would silently do nothing
     * there and the search would return no rows at all.
     */
    public function apply(Builder $query, mixed $value): Builder
    {
        $column = $query->getQuery()->getGrammar()->wrap($this->column());

        return $query->whereRaw("{$column} LIKE ? ESCAPE '\\'", [$this->wrap((string) $value)]);
    }

    public function description(): string
    {
        return 'contains';
    }
}
