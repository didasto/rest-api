<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Didasto\RestApi\Jobs\JobData;

class ReportData extends JobData
{
    public function __construct(
        public string $prefix = 'row',
        public int $count = 3,
        public ?int $failEvery = null,
    ) {}
}
