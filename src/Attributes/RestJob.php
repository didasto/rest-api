<?php

namespace Didasto\RestApi\Attributes;

use Attribute;

/**
 * Turns a controller into a job API: POST starts a run, GET reports its
 * progress, DELETE cancels it.
 *
 *     #[RestJob(key: 'member-export', uri: 'exports/members')]
 *     class MemberExportController extends JobController
 *     {
 *         protected ?string $storeRequest = ExportRequest::class;
 *
 *         public function jobs(?JobData $data): array
 *         {
 *             return array_map(fn ($year) => new ExportYear($year), $data->years);
 *         }
 *     }
 */
#[Attribute(Attribute::TARGET_CLASS)]
class RestJob
{
    public function __construct(
        public string $key,
        public ?string $uri = null,
        public array|string $middleware = [],
        public ?string $name = null,
        public ?string $tag = null,
        public bool $cancellable = false,
        public ?string $summary = null,
    ) {}
}
