<?php

namespace Didasto\RestApi\Jobs;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One started run. Its auto incrementing id is what the caller receives
 * from the POST and sends back on every GET.
 *
 * @property int $id
 * @property string $key
 * @property JobStatus $status
 */
class JobRun extends Model
{
    protected $guarded = [];

    /** The migration creates timestamp(6) columns; without this the microseconds are lost. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'status'      => JobStatus::class,
        'data'        => 'array',
        'result'      => 'array',
        'total'       => 'integer',
        'processed'   => 'integer',
        'failed'      => 'integer',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return $this->table ?? config('rest-api.jobs.table', 'api_job_runs');
    }

    // -------------------------------------------------------- Data and result
    /** The input of the run as a typed object. */
    public function data(): ?JobData
    {
        return $this->data_class
            ? $this->data_class::fromArray((array) $this->data)
            : null;
    }

    /** The current state of the result as a typed object. */
    public function result(): ?JobResult
    {
        return $this->result_class
            ? $this->result_class::fromArray((array) $this->result)
            : null;
    }

    /**
     * Advance the result while holding a row lock.
     *
     * Without the lock two jobs running at the same time would each write
     * back the state they read before they started: the slower one wins
     * and the other one's fields are gone.
     */
    public function updateResult(Closure $mutator): JobResult
    {
        return DB::transaction(function () use ($mutator) {
            $fresh = static::query()->lockForUpdate()->findOrFail($this->id);

            $result = $fresh->result();

            if (! $result) {
                throw new RuntimeException(static::class.': this run has no result class.');
            }

            $mutator($result);

            $fresh->forceFill(['result' => $result->toArray()])->save();

            $this->setRawAttributes($fresh->getAttributes(), true);

            return $result;
        });
    }

    // ----------------------------------------------------------------- Batch
    public function batch(): ?Batch
    {
        return $this->batch_id ? Bus::findBatch($this->batch_id) : null;
    }

    /**
     * Copy the current state out of the batch. Returns itself.
     *
     * Two quirks of Laravel's batches are handled here:
     *
     * 1. A permanently failed job does not decrement pending_jobs, so
     *    Laravel never sets finished_at once anything has failed and
     *    $batch->finished() stays false forever. A run is done when
     *    pending minus failed works out - the same arithmetic Laravel
     *    itself uses to fire the finally callback.
     *
     * 2. The failed_jobs counter is incremented on every failure while
     *    failed_job_ids is kept unique. After queue:retry the two drift
     *    apart, so the ids are counted instead: a job that fails three
     *    times is still one failed job.
     *
     * Retries within $tries never show up at all - the worker releases the
     * job back onto the queue and only reports a failure after the last
     * attempt.
     */
    public function sync(): static
    {
        if (! $this->status->isOpen()) {
            return $this;
        }

        $batch = $this->batch();

        if (! $batch) {
            return $this;
        }

        $this->total     = $batch->totalJobs;
        $this->failed    = count($batch->failedJobIds);
        $this->processed = max(0, $batch->totalJobs - $batch->pendingJobs);
        $this->status    = $this->statusOf($batch);

        if ($this->processed + $this->failed > 0 && ! $this->started_at) {
            $this->started_at = Carbon::now();
        }

        if (! $this->status->isOpen() && ! $this->finished_at) {
            $this->finished_at = $batch->finishedAt ?? Carbon::now();
        }

        if ($this->status === JobStatus::Failed && ! $this->message) {
            $this->message = "{$this->failed} of {$this->total} jobs failed.";
        }

        if ($this->isDirty()) {
            $this->save();
        }

        return $this;
    }

    /** Every job has run, successfully or permanently failed. */
    public function isBatchDone(Batch $batch): bool
    {
        return $batch->totalJobs > 0 && ($batch->pendingJobs - $batch->failedJobs) <= 0;
    }

    public function statusOf(Batch $batch): JobStatus
    {
        $done = $this->isBatchDone($batch);

        return match (true) {
            $batch->cancelled()                      => JobStatus::Cancelled,
            $done && count($batch->failedJobIds) > 0 => JobStatus::Failed,
            $done                                    => JobStatus::Finished,
            $batch->processedJobs() > 0
                || $batch->failedJobs > 0            => JobStatus::Processing,
            default                                  => JobStatus::Pending,
        };
    }

    public function progress(): int
    {
        if ($this->total <= 0) {
            return $this->status->isOpen() ? 0 : 100;
        }

        // Done is done - a failed job has run too.
        return min(100, (int) floor((($this->processed + $this->failed) / $this->total) * 100));
    }

    // ------------------------------------------------------------- Lifecycle
    public function markFailed(string $message): static
    {
        $this->forceFill([
            'status'      => JobStatus::Failed,
            'message'     => mb_substr($message, 0, 1000),
            'finished_at' => Carbon::now(),
        ])->save();

        return $this;
    }

    public function markFinished(?string $resultUrl = null): static
    {
        $this->forceFill([
            'status'      => JobStatus::Finished,
            'result_url'  => $resultUrl,
            'finished_at' => Carbon::now(),
        ])->save();

        return $this;
    }

    public function cancel(): static
    {
        $this->batch()?->cancel();

        return $this->sync();
    }

    // ---------------------------------------------------------------- Output
    /** Exactly the fields the documentation promises - nothing variable. */
    public function toApi(?string $resultUrl = null): array
    {
        return [
            'id'          => $this->id,
            'key'         => $this->key,
            'status'      => $this->status->value,
            'total'       => $this->total,
            'processed'   => $this->processed,
            'failed'      => $this->failed,
            'progress'    => $this->progress(),
            'message'     => $this->message,
            'result_url'  => $resultUrl ?? $this->result_url,
            'created_at'  => $this->zulu($this->created_at),
            'started_at'  => $this->zulu($this->started_at),
            'finished_at' => $this->zulu($this->finished_at),
        ];
    }

    public function zulu(?Carbon $moment): ?string
    {
        return $moment?->clone()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
