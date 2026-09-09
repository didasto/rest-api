<?php

namespace Didasto\RestApi\Http\Requests;

use Didasto\RestApi\Query\FilterSet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base class for every request of this package.
 *
 * rules()     feeds both the validation and the OpenAPI schema
 * filters()   declares the query filters the endpoint accepts
 * sortable()  and relations() limit ?sort and ?with
 *
 * PUT and PATCH share the same rules. To allow partial updates, write
 * 'sometimes' into the rule yourself - the package used to rewrite
 * 'required' automatically, which quietly did the wrong thing for
 * required_with, prohibited_unless and rule objects.
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

    /** @return array<string, mixed> field => filter, filter group, or a list of either */
    public function filters(): array
    {
        return [];
    }

    /** @return array<int, string> empty means every column may be sorted by */
    public function sortable(): array
    {
        return [];
    }

    /** @return array<int, string> empty disables ?with entirely */
    public function relations(): array
    {
        return [];
    }

    public function filterSet(): FilterSet
    {
        return FilterSet::fromDeclaration($this->filters());
    }
}
