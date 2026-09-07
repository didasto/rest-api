<?php

namespace Didasto\RestApi\Filters\Groups;

use Didasto\RestApi\Filters\Filter;

/**
 * Ein Buendel von Operatoren fuer ein Feld.
 *
 *   'id' => IdFilter::class
 *
 * erlaubt ?filter[id][eq], [ne], [lt], [lte], [gt], [gte], [in], [notIn], [null].
 *
 * Eine Gruppe ist selbst kein Filter - sie liefert nur die Einzelfilter,
 * damit Query und OpenAPI mit derselben Liste arbeiten.
 */
abstract class FilterGroup
{
    public function __construct(
        public string $field = '',
        public ?string $column = null,
    ) {}

    /** @return array<int, class-string<Filter>> */
    abstract public function filters(): array;

    /** JSON-Schema-Typ der Werte - fuers OpenAPI-Dokument. */
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
     * Die Gruppe in einzelne, einsatzbereite Filter aufloesen.
     *
     * @return array<string, Filter> Operator => Filter
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

    /** Haengt Typ und Format der Gruppe an das Schema der Einzelfilter. */
    public function decorate(Filter $filter): Filter
    {
        return $filter;
    }
}
