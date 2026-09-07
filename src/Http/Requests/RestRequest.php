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
 * Bei PATCH werden required-Regeln automatisch zu sometimes - ein
 * Teil-Update muss nicht das ganze Objekt mitschicken. Wer das nicht
 * will, ueberschreibt partialRules().
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

    protected function validationRules(): array
    {
        $rules = parent::validationRules();

        return $this->isMethod('PATCH') ? $this->partialRules($rules) : $rules;
    }

    /** required wird zu sometimes, alles andere bleibt. */
    public function partialRules(array $rules): array
    {
        $partial = [];

        foreach ($rules as $field => $rule) {
            $list = is_string($rule) ? explode('|', $rule) : (array) $rule;

            $list = array_values(array_filter(
                $list,
                fn ($item) => ! (is_string($item) && $item === 'required'),
            ));

            array_unshift($list, 'sometimes');

            $partial[$field] = $list;
        }

        return $partial;
    }
}
