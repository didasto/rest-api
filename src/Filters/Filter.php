<?php

namespace Didasto\RestApi\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ein einzelner Filteroperator auf einer Spalte.
 *
 * Jede Ableitung beantwortet drei Fragen: wie heisst der Operator in der
 * URL, wie wird der Rohwert gelesen, und was macht er mit dem Query.
 */
abstract class Filter
{
    public function __construct(
        public string $field = '',
        public ?string $column = null,
    ) {}

    /** Operatorname in der URL: ?filter[id][gte]=5 */
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

    /** Rohwert aus der Query in den Wert fuer das Query umwandeln. */
    public function parse(mixed $value): mixed
    {
        return $value;
    }

    /** JSON-Schema des Query-Parameters fuers OpenAPI-Dokument. */
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
