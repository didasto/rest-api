<?php

namespace Didasto\RestApi\Query;

use Didasto\RestApi\Filters\Filter;
use Didasto\RestApi\Filters\Groups\FilterGroup;

/**
 * Uebersetzt die Deklaration aus filters() in einsatzbereite Filter.
 *
 * Erlaubte Schreibweisen je Feld:
 *   'id'    => IdFilter::class                       // Gruppe
 *   'name'  => [EqualsFilter::class, LikeFilter::class]
 *   'saldo' => new NumericFilter(column: 'saldo_cent')
 *   'ort'   => [new StringFilter(column: 'adresse_ort')]
 */
class FilterSet
{
    /** @var array<string, array<string, Filter>> Feld => Operator => Filter */
    public array $filters = [];

    /** @var array<string, array{type: string, format: ?string}> */
    public array $types = [];

    public static function fromDeclaration(array $declaration): static
    {
        $set = new static();

        foreach ($declaration as $field => $spec) {
            foreach (is_array($spec) ? $spec : [$spec] as $item) {
                $set->add($field, $item);
            }
        }

        return $set;
    }

    public function add(string $field, Filter|FilterGroup|string $item): static
    {
        $instance = is_string($item) ? new $item() : $item;

        if ($instance instanceof FilterGroup) {
            $instance->for($field);
            $this->types[$field] = ['type' => $instance->type(), 'format' => $instance->format()];

            foreach ($instance->resolve() as $operator => $filter) {
                $this->filters[$field][$operator] = $filter;
            }

            return $this;
        }

        $instance->for($field);
        $this->types[$field] ??= ['type' => 'string', 'format' => null];
        $this->filters[$field][$instance->operator()] = $instance;

        return $this;
    }

    /** @return array<int, string> */
    public function fields(): array
    {
        return array_keys($this->filters);
    }

    /** @return array<string, Filter> */
    public function operators(string $field): array
    {
        return $this->filters[$field] ?? [];
    }

    public function get(string $field, string $operator): ?Filter
    {
        return $this->filters[$field][$operator] ?? null;
    }

    public function type(string $field): array
    {
        return $this->types[$field] ?? ['type' => 'string', 'format' => null];
    }

    public function isEmpty(): bool
    {
        return $this->filters === [];
    }
}
