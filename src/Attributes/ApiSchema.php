<?php

namespace Didasto\RestApi\Attributes;

use Attribute;

/**
 * Adds the details a validation rule cannot express.
 *
 *     #[ApiSchema(field: 'iban', example: 'DE02120300000000202051', format: 'iban')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ApiSchema
{
    public function __construct(
        public string $field,
        public ?string $description = null,
        public mixed $example = null,
        public ?string $format = null,
    ) {}
}
