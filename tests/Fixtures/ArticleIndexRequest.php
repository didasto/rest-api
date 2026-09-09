<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Didasto\RestApi\Filters\Groups\DateFilter;
use Didasto\RestApi\Filters\Groups\IdFilter;
use Didasto\RestApi\Filters\Groups\NumericFilter;
use Didasto\RestApi\Filters\Groups\StringFilter;
use Didasto\RestApi\Http\Requests\IndexRequest;

class ArticleIndexRequest extends IndexRequest
{
    public function filters(): array
    {
        return [
            'id'       => IdFilter::class,
            'title'    => StringFilter::class,
            'slug'     => StringFilter::class,
            'position' => NumericFilter::class,
            'created'  => new DateFilter(column: 'created_at'),
        ];
    }

    public function sortable(): array
    {
        return ['id', 'title', 'position'];
    }
}
