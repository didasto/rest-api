<?php

namespace Didasto\RestApi\Attributes;

use Attribute;

/**
 * Describes a hand written endpoint for the OpenAPI document. Purely
 * documentation - the routing is done by the Spatie route attributes.
 *
 *     #[Post('close')]
 *     #[ApiOperation(summary: 'Close the cash book', tag: 'Cash book')]
 *     public function close(CloseRequest $request) {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ApiOperation
{
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $tag = null,
        public ?string $request = null,
        public ?array $responses = null,
        public bool $deprecated = false,
        public array|string|null $security = null,
        public bool $hidden = false,
    ) {}
}
