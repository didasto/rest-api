<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Didasto\RestApi\Jobs\JobRun;
use Illuminate\Bus\Batch;

/** A run whose batch is supplied by the test instead of the queue. */
class RecordedRun extends JobRun
{
    public ?Batch $fake = null;

    public function batch(): ?Batch
    {
        return $this->fake;
    }
}
