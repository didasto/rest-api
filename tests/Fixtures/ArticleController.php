<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Didasto\RestApi\Attributes\RestResource;
use Didasto\RestApi\Http\Controllers\RestController;

#[RestResource(model: Article::class, uri: 'articles')]
class ArticleController extends RestController
{
    public function requestFor(string $action): ?string
    {
        return match ($action) {
            'index'  => ArticleIndexRequest::class,
            'store'  => ArticleStoreRequest::class,
            'update' => ArticleUpdateRequest::class,
            default  => null,
        };
    }
}
