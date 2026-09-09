<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Didasto\RestApi\Http\Requests\RestRequest;

class ReportRequest extends RestRequest
{
    public function rules(): array
    {
        return [
            'prefix'    => ['string', 'max:20'],
            'count'     => ['integer', 'min:1', 'max:20'],
            'failEvery' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
