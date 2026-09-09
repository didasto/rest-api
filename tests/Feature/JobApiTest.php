<?php

namespace Didasto\RestApi\Tests\Feature;

use Didasto\RestApi\Jobs\JobRun;
use Didasto\RestApi\Jobs\JobStatus;
use Didasto\RestApi\Tests\Fixtures\ReportResult;
use Didasto\RestApi\Tests\TestCase;

/**
 * The queue runs synchronously in tests, so a batch is finished by the
 * time the request returns. In a browser the intermediate states are
 * visible instead.
 */
class JobApiTest extends TestCase
{
    protected function start(array $payload = []): array
    {
        return $this->postJson('/api/jobs/report', $payload)->assertStatus(202)->json();
    }

    public function test_post_creates_a_run_and_returns_its_id(): void
    {
        $response = $this->postJson('/api/jobs/report', ['count' => 3]);

        $response->assertStatus(202)
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('key', 'report')
            ->assertJsonStructure([
                'id', 'key', 'status', 'total', 'processed', 'failed',
                'progress', 'message', 'result_url', 'created_at', 'started_at', 'finished_at',
            ]);

        $this->assertStringEndsWith(
            '/api/jobs/report/'.$response->json('id'),
            $response->headers->get('Location'),
        );

        $this->assertDatabaseHas('api_job_runs', ['id' => $response->json('id'), 'key' => 'report']);
    }

    public function test_the_total_grows_while_the_run_is_going(): void
    {
        $run = $this->start(['count' => 4]);

        $state = $this->getJson("/api/jobs/report/{$run['id']}")->assertOk()->json();

        // One fan out job plus the four it appended.
        $this->assertSame(5, $state['total']);
        $this->assertSame(5, $state['processed']);
        $this->assertSame(0, $state['failed']);
        $this->assertSame(100, $state['progress']);
        $this->assertSame(JobStatus::Finished->value, $state['status']);
        $this->assertNotNull($state['finished_at']);

        $this->assertSame(4, \Didasto\RestApi\Tests\Fixtures\Article::query()->count());
    }

    public function test_the_result_object_is_filled_by_the_chain(): void
    {
        $run = $this->start(['count' => 3, 'prefix' => 'chain']);

        /** @var ReportResult $result */
        $result = JobRun::query()->findOrFail($run['id'])->result();

        $this->assertSame(3, $result->created);
        $this->assertCount(3, $result->articleIds);
    }

    public function test_the_input_object_keeps_its_defaults(): void
    {
        // count and prefix are promoted constructor parameters with
        // defaults, which fromArray() has to honour.
        $run = $this->start([]);

        $data = JobRun::query()->findOrFail($run['id'])->data();

        $this->assertSame('row', $data->prefix);
        $this->assertSame(3, $data->count);
        $this->assertNull($data->failEvery);
    }

    public function test_result_url_points_at_the_created_records(): void
    {
        $run = $this->start(['count' => 2]);

        $url = $this->getJson("/api/jobs/report/{$run['id']}")->json('result_url');

        $this->assertNotNull($url);
        $this->assertCount(2, $this->getJson($url)->assertOk()->json());
    }

    // How failures are counted is covered by JobRunStateTest: the sync
    // queue driver rethrows a failing job into the caller instead of
    // recording it, so those cases cannot arise here.

    public function test_a_finished_run_is_not_read_from_the_batch_again(): void
    {
        $run = JobRun::query()->findOrFail($this->start(['count' => 2])['id'])->sync();

        $this->assertSame(JobStatus::Finished, $run->status);

        $run->forceFill(['batch_id' => 'gone'])->save();

        // sync() returns early on a closed run, so a missing batch is fine.
        $this->assertSame(JobStatus::Finished, $run->fresh()->sync()->status);
    }

    public function test_an_unknown_run_gives_404(): void
    {
        $this->getJson('/api/jobs/report/999999')->assertNotFound();
    }

    public function test_the_input_is_validated(): void
    {
        $this->postJson('/api/jobs/report', ['count' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('count');
    }

    public function test_timestamps_are_reported_in_zulu_time_with_microseconds(): void
    {
        $run = $this->start(['count' => 1]);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            $run['created_at'],
        );
    }
}
