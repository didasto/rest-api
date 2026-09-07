<?php

namespace Didasto\RestApi\Http\Requests;

use Didasto\RestApi\Query\FilterSet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Basis fuer alle Request-Klassen des Packages.
 *
 * rules()      speist Validierung UND OpenAPI-Schema.
 * filters()    deklariert die erlaubten Query-Filter.
 * sortable()   und relations() begrenzen ?sort und ?with.
 *
 * PUT und PATCH teilen sich dieselben Regeln. Wer ein Teil-Update
 * erlauben will, schreibt sometimes selbst in die Regel - frueher hat das
 * Package required automatisch umgeschrieben, was bei required_with,
 * prohibited_unless oder Rule-Objekten stillschweigend das Falsche tat.
 */
class RestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> Feld => Filter, Filtergruppe oder Liste davon */
    public function filters(): array
    {
        return [];
    }

    /** @return array<int, string> Leer = jede Spalte erlaubt */
    public function sortable(): array
    {
        return [];
    }

    /** @return array<int, string> Leer = ?with abgeschaltet */
    public function relations(): array
    {
        return [];
    }

    public function filterSet(): FilterSet
    {
        return FilterSet::fromDeclaration($this->filters());
    }

}
