<?php

namespace Didasto\RestApi\Http\Controllers;

use Didasto\RestApi\Attributes\RestJob;
use Didasto\RestApi\Http\Requests\RestRequest;
use Didasto\RestApi\Jobs\JobData;
use Didasto\RestApi\Jobs\JobResult;
use Didasto\RestApi\Jobs\JobRun;
use Didasto\RestApi\Jobs\JobStatus;
use Illuminate\Bus\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Base class for job APIs.
 *
 * POST creates a run, puts the jobs on the queue as a batch and returns
 * the id of the run. GET reports the state - the numbers are read live
 * from the batch, so the jobs themselves report nothing.
 *
 * Only jobs() has to be implemented. The remaining methods are extension
 * points:
 *
 *   jobs()        which jobs make up the run
 *   data()        how the input object is built
 *   sanitize()    what of the input is stored, to keep secrets out
 *   dispatch()    how the batch is put on the queue
 *   resultUrl()   where the caller picks up what the run produced
 *   find()        how a run is looked up
 */
abstract class JobController
{
    protected ?string $storeRequest = null;

    /** Input of the run. Required, so the chain starts out typed. */
    protected ?string $dataClass = null;

    /** Result object the chain fills together. Null means none. */
    protected ?string $resultClass = null;

    protected ?string $model = JobRun::class;

    // ------------------------------------------------------------- Actions
    public function store(Request $request): JsonResponse
    {
        $data = $this->data($this->formRequest()->validated());
        $jobs = $this->jobs($data);

        if ($jobs === []) {
            throw new RuntimeException(static::class.': jobs() returned nothing.');
        }

        $run = $this->newRun($data, $this->countJobs($jobs));

        try {
            $batch = $this->dispatch($this->attach($jobs, $run), $run);
            $run->forceFill(['batch_id' => $batch->id, 'total' => $batch->totalJobs])->save();
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage());

            throw $exception;
        }

        return new JsonResponse(
            $run->toApi($this->resultUrl($run)),
            202,
            [
                'Location'    => $this->statusUrl($run),
                'Retry-After' => (string) $this->retryAfter(),
            ],
        );
    }

    public function show(Request $request, string|int $id): JsonResponse
    {
        $run = $this->find($id);

        $headers = $run->status->isOpen()
            ? ['Retry-After' => (string) $this->retryAfter()]
            : [];

        return new JsonResponse($run->toApi($this->resultUrl($run)), 200, $headers);
    }

    public function cancel(Request $request, string|int $id): JsonResponse
    {
        $run = $this->find($id)->cancel();

        return new JsonResponse($run->toApi($this->resultUrl($run)), 200);
    }

    // ---------------------------------------------------------------- Work
    /**
     * The jobs of this run.
     *
     * One element is one job running alongside the others. A nested array
     * is a chain that runs in order - that is Laravel's own batch
     * convention, not an invention of this package.
     *
     *     return [
     *         [SearchProductJob::class, CreateProductJob::class],  // in order
     *         new CheckStockJob(),                                  // alongside
     *     ];
     *
     * Class names are resolved; pass a ready made instance when the job
     * needs constructor arguments. The run id is attached by the package.
     *
     * @return array<int, object|string|array>
     */
    abstract public function jobs(?JobData $data): array;

    /**
     * Where the caller picks up what the run produced - a real route, not
     * an embedded payload. Null while there is nothing to fetch.
     */
    public function resultUrl(JobRun $run): ?string
    {
        return $run->result_url;
    }

    public function dispatch(array $jobs, JobRun $run): Batch
    {
        $batch = Bus::batch($jobs)
            ->name($this->key().'#'.$run->id)
            ->allowFailures();

        $queue = config('rest-api.jobs.queue');

        return $queue ? $batch->onQueue($queue)->dispatch() : $batch->dispatch();
    }

    // ------------------------------------------------------- Data and runs
    public function data(array $validated): ?JobData
    {
        return $this->dataClass ? $this->dataClass::fromArray($validated) : null;
    }

    public function newResult(): ?JobResult
    {
        return $this->resultClass ? $this->resultClass::fromArray([]) : null;
    }

    /** Instantiate the jobs and hand each of them the run id, chains included. */
    public function attach(array $jobs, JobRun $run): array
    {
        return array_map(function ($job) use ($run) {
            if (is_array($job)) {
                return $this->attach($job, $run);
            }

            $instance = is_string($job) ? new $job() : $job;

            return method_exists($instance, 'forJobRun')
                ? $instance->forJobRun($run->id)
                : $instance;
        }, $jobs);
    }

    /** Chains count per link - inside a batch they are individual jobs. */
    public function countJobs(array $jobs): int
    {
        $count = 0;

        foreach ($jobs as $job) {
            $count += is_array($job) ? $this->countJobs($job) : 1;
        }

        return $count;
    }

    public function newRun(?JobData $data, int $total): JobRun
    {
        $class  = $this->model ?? JobRun::class;
        $result = $this->newResult();

        return $class::create([
            'key'          => $this->key(),
            'status'       => JobStatus::Pending,
            'total'        => $total,
            'processed'    => 0,
            'failed'       => 0,
            'data_class'   => $data ? $data::class : null,
            'data'         => $data ? $this->sanitize($data->toArray()) : null,
            'result_class' => $result ? $result::class : null,
            'result'       => $result?->toArray(),
            'created_by'   => Auth::id(),
        ]);
    }

    /** What of the input is stored - a good place to drop secrets. */
    public function sanitize(array $data): array
    {
        return $data;
    }

    public function find(string|int $id): JobRun
    {
        $class = $this->model ?? JobRun::class;

        return $class::query()
            ->where('key', $this->key())
            ->findOrFail($id)
            ->sync();
    }

    // ------------------------------------------------------------ Context
    public function attribute(): ?RestJob
    {
        $attributes = (new ReflectionClass(static::class))->getAttributes(RestJob::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    public function key(): string
    {
        $attribute = $this->attribute();

        if (! $attribute) {
            throw new RuntimeException(static::class.': #[RestJob] is missing.');
        }

        return $attribute->key;
    }

    /** Same idea as on resources: one place that knows the request classes. */
    public function requestFor(string $action): ?string
    {
        return $action === 'store' ? $this->storeRequest : null;
    }

    public function formRequest(): RestRequest
    {
        if (! $this->storeRequest) {
            throw new RuntimeException(static::class.': $storeRequest is not set.');
        }

        return app($this->storeRequest);
    }

    public function statusUrl(JobRun $run): string
    {
        $name = ($this->attribute()?->name ?? $this->key()).'.show';

        return route($name, ['id' => $run->id]);
    }

    public function retryAfter(): int
    {
        return (int) config('rest-api.jobs.retry_after', 2);
    }
}
