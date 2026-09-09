<?php

namespace Didasto\RestApi\OpenApi;

use Illuminate\Http\JsonResponse;

/**
 * Serves the OpenAPI document on the route configured in rest-api.php.
 */
class DocumentationController
{
    public function __construct(public Generator $generator) {}

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            $this->document(),
            200,
            ['Content-Type' => 'application/json'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    public function document(): array
    {
        if (! config('rest-api.openapi.cache')) {
            return $this->generator->generate();
        }

        return cache()->rememberForever('rest-api.openapi', fn () => $this->generator->generate());
    }
}
