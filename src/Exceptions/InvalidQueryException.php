<?php

namespace Didasto\RestApi\Exceptions;

use Didasto\RestApi\Query\FilterSet;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when the query string asks for something the resource does not
 * offer. The message always names what would have been allowed, so the
 * caller can correct the request without reading the source.
 */
class InvalidQueryException extends HttpException
{
    public static function malformedFilter(): self
    {
        return new self(422, 'filter must be an object: filter[field][operator]=value');
    }

    public static function unknownFilter(string $field, string $operator, FilterSet $filters): self
    {
        $known = $filters->operators($field);

        $hint = $known === []
            ? 'Allowed fields: '.implode(', ', $filters->fields())
            : "Allowed operators for '{$field}': ".implode(', ', array_keys($known));

        return new self(422, "Unknown filter '{$field}[{$operator}]'. {$hint}");
    }

    public static function unknownSort(string $column, array $sortable): self
    {
        return new self(422, "Cannot sort by '{$column}'. Allowed: ".implode(', ', $sortable));
    }

    public static function unknownRelation(string $relation, array $allowed): self
    {
        return new self(422, "Relation '{$relation}' is not exposed. Allowed: ".implode(', ', $allowed));
    }
}
