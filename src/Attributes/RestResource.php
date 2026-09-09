<?php

namespace Didasto\RestApi\Attributes;

use Attribute;

/**
 * Turns a controller into a model resource.
 *
 *     #[RestResource(model: Member::class, except: ['destroy'])]
 *     class MemberController extends RestController {}
 *
 * Actions: index, show, store, update (PUT and PATCH), destroy.
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

    /**
     * Resolve which actions this resource exposes.
     *
     * @param  array<int, string>  $defaults
     * @return array<int, string>
     */
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
