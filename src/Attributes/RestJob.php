<?php

namespace Didasto\RestApi\Attributes;

use Attribute;

/**
 * Macht aus einem Controller eine Job-API: POST stoesst an, GET liefert
 * den Stand.
 *
 *   #[RestJob(key: 'mitglieder-export', uri: 'exporte/mitglieder')]
 *   class MitgliederExportController extends JobController
 *   {
 *       protected ?string $storeRequest = ExportRequest::class;
 *
 *       public function jobs(array $data): array
 *       {
 *           return array_map(fn ($jahr) => new ExportiereJahr($jahr), $data['jahre']);
 *       }
 *   }
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
