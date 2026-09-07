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
 * Basis fuer Job-APIs.
 *
 * POST legt einen Lauf an, wirft die Jobs als Batch in die Queue und gibt
 * die id des Laufs zurueck. GET liefert den Stand - die Zahlen kommen live
 * aus dem Batch, die Jobs muessen nichts zurueckmelden.
 *
 * Zu implementieren ist nur jobs(); resultUrl() lohnt sich, sobald der
 * Lauf etwas erzeugt, das der Client danach abholen soll.
 */
abstract class JobController
{
    protected ?string $storeRequest = null;

    /** Eingangsdaten des Laufs - Pflicht, damit die Kette typisiert anfaengt. */
    protected ?string $dataClass = null;

    /** Ergebnisobjekt, das die Kette gemeinsam fuellt. null = keines. */
    protected ?string $resultClass = null;

    protected ?string $model = JobRun::class;

    // ------------------------------------------------------------- Aktionen
    public function store(Request $request): JsonResponse
    {
        $data = $this->data($this->formRequest()->validated());
        $jobs = $this->jobs($data);

        if ($jobs === []) {
            throw new RuntimeException(static::class.': jobs() hat nichts zurueckgegeben.');
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
            ['Location' => $this->statusUrl($run), 'Retry-After' => (string) $this->retryAfter()],
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

    // ------------------------------------------------------------- Aufgaben
    /**
     * Die Jobs dieses Laufs.
     *
     * Ein Element = ein Job, der gleichzeitig mit den uebrigen laeuft.
     * Ein verschachteltes Array = eine Kette, die der Reihe nach laeuft -
     * das ist Laravels eigene Batch-Konvention, keine Erfindung dieses
     * Packages.
     *
     *   return [
     *       [SucheProduktdatenJob::class, ErzeugeProduktJob::class],  // nacheinander
     *       new PruefeBestandJob(),                                    // parallel dazu
     *   ];
     *
     * Klassennamen werden aufgeloest; wer Konstruktorargumente braucht,
     * gibt eine fertige Instanz zurueck. Die Lauf-Nummer haengt das
     * Package selbst an - im Job ist dafuer nichts zu tun.
     *
     * @return array<int, object|string|array>
     */
    abstract public function jobs(?JobData $data): array;

    // ------------------------------------------------- Daten und Ergebnis
    public function data(array $validated): ?JobData
    {
        return $this->dataClass ? $this->dataClass::fromArray($validated) : null;
    }

    public function newResult(): ?JobResult
    {
        return $this->resultClass ? $this->resultClass::fromArray([]) : null;
    }

    /** Jobs instanziieren und ihnen die Lauf-Nummer mitgeben - auch in Ketten. */
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

    /** Ketten zaehlen mit jedem Glied - sie sind im Batch einzelne Jobs. */
    public function countJobs(array $jobs): int
    {
        $count = 0;

        foreach ($jobs as $job) {
            $count += is_array($job) ? $this->countJobs($job) : 1;
        }

        return $count;
    }

    /**
     * Wohin der Client nach dem Lauf greifen soll - eine echte Route, kein
     * eingebettetes Ergebnis. null, solange es nichts abzuholen gibt.
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

    // ------------------------------------------------------------- Laeufe
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

    /** Was von den Eingangsdaten gespeichert wird - hier lassen sich Geheimnisse entfernen. */
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

    // ------------------------------------------------------------- Umfeld
    public function attribute(): ?RestJob
    {
        $attributes = (new ReflectionClass(static::class))->getAttributes(RestJob::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    public function key(): string
    {
        $attribute = $this->attribute();

        if (! $attribute) {
            throw new RuntimeException(static::class.': #[RestJob] fehlt.');
        }

        return $attribute->key;
    }

    public function requestClass(string $action): ?string
    {
        return $action === 'store' ? $this->storeRequest : null;
    }

    public function formRequest(): RestRequest
    {
        if (! $this->storeRequest) {
            throw new RuntimeException(static::class.': $storeRequest ist nicht gesetzt.');
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
