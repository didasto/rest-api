<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Illuminate\Bus\Batch;
use Illuminate\Support\Carbon;

/**
 * Mirrors Laravel's own bookkeeping without a queue behind it:
 * a success decrements pending, a permanent failure only increments
 * failed and leaves pending where it is.
 */
class FakeBatch extends Batch
{
    public function __construct(
        int $total,
        int $pending,
        int $failedCounter = 0,
        array $failedIds = [],
        ?Carbon $finishedAt = null,
        ?Carbon $cancelledAt = null,
    ) {
        $this->id            = 'fake-batch';
        $this->name          = 'fake';
        $this->totalJobs     = $total;
        $this->pendingJobs   = $pending;
        $this->failedJobs    = $failedCounter;
        $this->failedJobIds  = $failedIds;
        $this->finishedAt    = $finishedAt;
        $this->cancelledAt   = $cancelledAt;
        $this->options       = [];
    }

    public function processedJobs(): int
    {
        return $this->totalJobs - $this->pendingJobs;
    }

    public function finished(): bool
    {
        return $this->finishedAt !== null;
    }

    public function cancelled(): bool
    {
        return $this->cancelledAt !== null;
    }
}
