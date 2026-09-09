<?php

namespace Didasto\RestApi\Jobs;

/**
 * The input of a run - what the first job starts from.
 *
 * Built once from the validated request and never changed afterwards.
 * Every job of the chain reads the same instance through $this->data().
 *
 *     class ProductSearchData extends JobData
 *     {
 *         public string $term;
 *         public ?string $brand = null;
 *         public ?Source $source = null;   // enums are converted as well
 *     }
 */
abstract class JobData extends JobPayload
{
}
