<?php

namespace Didasto\RestApi\Exceptions;

use Didasto\RestApi\Query\FilterSet;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidQueryException extends HttpException
{
    public static function malformedFilter(): self
    {
        return new self(422, 'filter muss ein Objekt sein: filter[feld][operator]=wert');
    }

    public static function unknownFilter(string $field, string $operator, FilterSet $filters): self
    {
        $known = $filters->operators($field);

        $hint = $known === []
            ? 'Erlaubte Felder: '.implode(', ', $filters->fields())
            : "Erlaubte Operatoren fuer '{$field}': ".implode(', ', array_keys($known));

        return new self(422, "Unbekannter Filter '{$field}[{$operator}]'. {$hint}");
    }

    public static function unknownSort(string $column, array $sortable): self
    {
        return new self(422, "Nach '{$column}' kann nicht sortiert werden. Erlaubt: ".implode(', ', $sortable));
    }

    public static function unknownRelation(string $relation, array $allowed): self
    {
        return new self(422, "Relation '{$relation}' ist nicht freigegeben. Erlaubt: ".implode(', ', $allowed));
    }
}
