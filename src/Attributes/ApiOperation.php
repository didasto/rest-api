<?php

namespace Didasto\RestApi\Attributes;

use Attribute;

/**
 * Beschreibt einen handgeschriebenen Endpunkt fuers OpenAPI-Dokument.
 * Rein dokumentarisch - das Routing machen die Spatie-Attribute.
 *
 *   #[Get('kasse/abschluss')]
 *   #[ApiOperation(summary: 'Kassenabschluss', tag: 'Kasse', request: AbschlussRequest::class)]
 *   public function abschluss(AbschlussRequest $request) {}
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
