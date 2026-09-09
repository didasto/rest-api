<?php

namespace Didasto\RestApi\Filters\Groups;

use Didasto\RestApi\Filters\Filter;

/**
 * A bundle of operators for one field.
 *
 *     'id' => IdFilter::class
 *
 * allows ?filter[id][eq], [ne], [lt], [lte], [gt], [gte], [in], [notIn]
 * and [null].
 *
 * A group is not a filter itself. It only hands out the individual
 * filters, so the query and the OpenAPI document always work from the
 * very same list and cannot drift apart.
 */
abstract class FilterGroup
{
    public function __construct(
        public string $field = '',
        public ?string $column = null,
    ) {}

    /** @return array<int, class-string<Filter>|Filter> */
    abstract public function filters(): array;

    /** JSON schema type of the values, used by the OpenAPI generator. */
    public function type(): string
    {
        return 'string';
    }

    public function format(): ?string
    {
        return null;
    }

    public function for(string $field, ?string $column = null): static
    {
        $this->field  = $field;
        $this->column = $column ?? $this->column;

        return $this;
    }

    /**
     * Expand the group into ready to use filters.
     *
     * @return array<string, Filter> operator => filter
     */
    public function resolve(): array
    {
        $resolved = [];

        foreach ($this->filters() as $class) {
            $filter = $class instanceof Filter ? $class : new $class();
            $filter->for($this->field, $this->column);

            $resolved[$filter->operator()] = $this->decorate($filter);
        }

        return $resolved;
    }

    /** Last chance to adjust a filter before it is used. */
    public function decorate(Filter $filter): Filter
    {
        return $filter;
    }
}
