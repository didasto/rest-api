<?php

namespace Didasto\RestApi\Jobs;

use Closure;
use RuntimeException;

/**
 * Add this to every job of a run. The controller attaches the run id when
 * the batch is dispatched, so there is nothing to wire up in the job.
 *
 *     class FindAsinJob implements ShouldQueue
 *     {
 *         use Batchable, Queueable, InteractsWithJobRun;
 *
 *         public function handle(): void
 *         {
 *             $term = $this->data()->term;
 *
 *             $this->updateResult(function (ProductSearchResult $result) use ($asin) {
 *                 $result->asin = $asin;
 *             });
 *         }
 *     }
 */
trait InteractsWithJobRun
{
    public ?int $jobRunId = null;

    public function forJobRun(int $id): static
    {
        $this->jobRunId = $id;

        return $this;
    }

    public function run(): JobRun
    {
        if (! $this->jobRunId) {
            throw new RuntimeException(static::class.': this job does not belong to a run.');
        }

        return JobRun::query()->findOrFail($this->jobRunId);
    }

    /** The input of the run - the same for every job of the chain. */
    public function data(): ?JobData
    {
        return $this->run()->data();
    }

    /** The result as the preceding jobs left it. */
    public function result(): ?JobResult
    {
        return $this->run()->result();
    }

    /**
     * Advance the result. Runs under a row lock so jobs running at the
     * same time cannot overwrite each other - each of them sees the
     * current state at the moment it writes.
     */
    public function updateResult(Closure $mutator): JobResult
    {
        return $this->run()->updateResult($mutator);
    }
}
