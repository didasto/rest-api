<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Didasto\RestApi\Http\Requests\RestRequest;

class ArticleStoreRequest extends RestRequest
{
    public function rules(): array
    {
        return [
            'id'       => ['integer'],   // deliberately present: must not reach the request body
            'title'    => ['required', 'string', 'max:120'],
            'slug'     => ['nullable', 'string'],
            'position' => ['integer', 'min:0'],
        ];
    }
}
