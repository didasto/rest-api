<?php

namespace Didasto\RestApi\Attributes;

use Attribute;

/**
 * Macht aus einem Controller eine Model-Ressource.
 *
 *   #[RestResource(model: Mitglied::class, except: ['destroy'])]
 *   class MitgliedController extends RestController {}
 *
 * Aktionen: index, show, store, update (PUT und PATCH), destroy.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class RestResource
{
    public function __construct(
        public string $model,
        public ?string $uri = null,
        public array|string $only = [],
        public array|string $except = [],
        public array|string $middleware = [],
        public ?string $name = null,
        public ?string $parameter = null,
        public ?string $key = null,
        public ?string $tag = null,
    ) {}

    /** @return array<int, string> */
    public function actions(array $defaults): array
    {
        $only   = (array) $this->only;
        $except = (array) $this->except;

        if ($only !== []) {
            return array_values(array_intersect($defaults, $only));
        }

        return array_values(array_diff($defaults, $except));
    }
}
