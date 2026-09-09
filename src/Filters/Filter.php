<?php

namespace Didasto\RestApi\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * A single filter operator on one column.
 *
 * Every subclass answers three questions: what the operator is called in
 * the URL, how the raw value is read, and what it does to the query.
 */
abstract class Filter
{
    public function __construct(
        public string $field = '',
        public ?string $column = null,
    ) {}

    /** Operator name in the URL: ?filter[id][gte]=5 */
    abstract public function operator(): string;

    abstract public function apply(Builder $query, mixed $value): Builder;

    public function column(): string
    {
        return $this->column ?? $this->field;
    }

    public function for(string $field, ?string $column = null): static
    {
        $this->field  = $field;
        $this->column = $column ?? $this->column;

        return $this;
    }

    /** Turn the raw query value into what the query builder expects. */
    public function parse(mixed $value): mixed
    {
        return $value;
    }

    /** JSON schema of the query parameter, used by the OpenAPI generator. */
    public function schema(): array
    {
        return ['type' => 'string'];
    }

    public function description(): string
    {
        return static::class;
    }

    public function parameterName(string $filterKey): string
    {
        return "{$filterKey}[{$this->field}][{$this->operator()}]";
    }
}
