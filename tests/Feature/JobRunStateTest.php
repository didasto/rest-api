<?php

namespace Didasto\RestApi\Tests\Feature;

use Didasto\RestApi\Jobs\JobStatus;
use Didasto\RestApi\Tests\Fixtures\FakeBatch;
use Didasto\RestApi\Tests\Fixtures\RecordedRun;
use Didasto\RestApi\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * How a run reads its state out of the batch.
 *
 * This is driven by a batch double rather than a real queue, because the
 * sync driver rethrows a failing job into the caller instead of recording
 * it - the very cases worth testing could not happen there.
 */
class JobRunStateTest extends TestCase
{
    protected function runWith(FakeBatch $batch): array
    {
        $run = RecordedRun::query()->create([
            'key'       => 'report',
            'batch_id'  => 'fake-batch',
            'status'    => JobStatus::Pending,
            'total'     => 0,
            'processed' => 0,
            'failed'    => 0,
        ]);

        $run->fake = $batch;

        return $run->sync()->toApi();
    }

    public function test_nothing_has_started_yet(): void
    {
        $state = $this->runWith(new FakeBatch(total: 7, pending: 7));

        $this->assertSame(JobStatus::Pending->value, $state['status']);
        $this->assertSame(0, $state['processed']);
        $this->assertSame(0, $state['progress']);
        $this->assertNull($state['started_at']);
    }

    public function test_partly_done(): void
    {
        $state = $this->runWith(new FakeBatch(total: 7, pending: 2));

        $this->assertSame(JobStatus::Processing->value, $state['status']);
        $this->assertSame(5, $state['processed']);
        $this->assertSame(71, $state['progress']);
        $this->assertNotNull($state['started_at']);
        $this->assertNull($state['finished_at']);
    }

    public function test_finished_without_failures(): void
    {
        $state = $this->runWith(new FakeBatch(
            total: 7,
            pending: 0,
            finishedAt: Carbon::parse('2026-09-07 21:50:00'),
        ));

        $this->assertSame(JobStatus::Finished->value, $state['status']);
        $this->assertSame(7, $state['processed']);
        $this->assertSame(100, $state['progress']);
        $this->assertSame('2026-09-07T21:50:00.000000Z', $state['finished_at']);
    }

    /**
     * A permanently failed job never decrements pending_jobs, so Laravel
     * leaves finished_at empty and $batch->finished() stays false forever.
     * The run still has to end.
     */
    public function test_finished_with_failures_even_though_laravel_never_marks_the_batch_finished(): void
    {
        $state = $this->runWith(new FakeBatch(
            total: 7,
            pending: 2,
            failedCounter: 2,
            failedIds: ['job-3', 'job-5'],
        ));

        $this->assertSame(JobStatus::Failed->value, $state['status']);
        $this->assertSame(5, $state['processed']);
        $this->assertSame(2, $state['failed']);
        $this->assertSame(100, $state['progress'], 'a failed job has run too');
        $this->assertSame('2 of 7 jobs failed.', $state['message']);
        $this->assertNotNull($state['finished_at']);
    }

    /**
     * The failed_jobs counter grows on every failure while failed_job_ids
     * stays unique. After queue:retry the two drift apart, and a job that
     * failed twice must still count once.
     */
    public function test_a_job_that_failed_twice_is_still_one_failure(): void
    {
        $state = $this->runWith(new FakeBatch(
            total: 3,
            pending: 1,
            failedCounter: 2,
            failedIds: ['job-2'],
        ));

        $this->assertSame(1, $state['failed']);
        $this->assertSame(JobStatus::Failed->value, $state['status']);
    }

    /** A retry that finally succeeded removes the id, so the run recovers. */
    public function test_a_successful_retry_lets_the_run_finish(): void
    {
        $state = $this->runWith(new FakeBatch(
            total: 3,
            pending: 0,
            failedCounter: 2,
            failedIds: [],
        ));

        $this->assertSame(JobStatus::Finished->value, $state['status']);
        $this->assertSame(0, $state['failed']);
    }

    public function test_a_cancelled_run(): void
    {
        $state = $this->runWith(new FakeBatch(
            total: 7,
            pending: 3,
            cancelledAt: Carbon::parse('2026-09-07 21:50:00'),
        ));

        $this->assertSame(JobStatus::Cancelled->value, $state['status']);
        $this->assertSame(4, $state['processed']);
        $this->assertNotNull($state['finished_at']);
    }

    public function test_a_growing_total_is_picked_up(): void
    {
        $run = RecordedRun::query()->create([
            'key'       => 'report',
            'batch_id'  => 'fake-batch',
            'status'    => JobStatus::Pending,
            'total'     => 1,
            'processed' => 0,
            'failed'    => 0,
        ]);

        $run->fake = new FakeBatch(total: 1, pending: 1);
        $this->assertSame(1, $run->sync()->toApi()['total']);

        // The first job appended three more to the same batch.
        $run->fake = new FakeBatch(total: 4, pending: 3);
        $state = $run->sync()->toApi();

        $this->assertSame(4, $state['total']);
        $this->assertSame(1, $state['processed']);
        $this->assertSame(25, $state['progress']);
    }
}
