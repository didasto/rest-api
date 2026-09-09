<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Didasto\RestApi\Jobs\JobResult;

class ReportResult extends JobResult
{
    public ?int $created = null;

    /** @var array<int, int>|null */
    public ?array $articleIds = null;
}
