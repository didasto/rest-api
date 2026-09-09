<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Didasto\RestApi\Http\Requests\RestRequest;

class ArticleUpdateRequest extends RestRequest
{
    public function rules(): array
    {
        return [
            'title'    => ['sometimes', 'string', 'max:120'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
