<?php

namespace Didasto\RestApi\Jobs;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Ein angestossener Lauf. Die fortlaufende id ist das, was der Client
 * beim POST bekommt und beim GET wieder mitschickt.
 *
 * Die Fortschrittszahlen holt sync() live aus Laravels Batch - die Jobs
 * selbst muessen nichts zurueckmelden. Erst wenn der Batch fertig ist,
 * werden die Zahlen festgeschrieben.
 *
 * @property int $id
 * @property string $key
 * @property JobStatus $status
 */
class JobRun extends Model
{
    protected $guarded = [];

    /** Die Migration legt timestamp(6) an - ohne das hier waeren die Mikrosekunden weg. */
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

    // ------------------------------------------------------- Daten und Ergebnis
    /** Die Eingangsdaten des Laufs, als typisiertes Objekt. */
    public function data(): ?JobData
    {
        return $this->data_class
            ? $this->data_class::fromArray((array) $this->data)
            : null;
    }

    /** Der aktuelle Stand des Ergebnisses, als typisiertes Objekt. */
    public function result(): ?JobResult
    {
        return $this->result_class
            ? $this->result_class::fromArray((array) $this->result)
            : null;
    }

    /**
     * Das Ergebnis unter einer Zeilensperre fortschreiben.
     *
     * Ohne die Sperre wuerden zwei gleichzeitig laufende Jobs jeweils den
     * Stand von vor ihrem Start zurueckschreiben - der langsamere gewinnt,
     * und die Felder des anderen waeren weg.
     */
    public function updateResult(Closure $mutator): JobResult
    {
        return DB::transaction(function () use ($mutator) {
            $fresh = static::query()->lockForUpdate()->findOrFail($this->id);

            $result = $fresh->result();

            if (! $result) {
                throw new \RuntimeException(static::class.': fuer diesen Lauf ist keine JobResult-Klasse hinterlegt.');
            }

            $mutator($result);

            $fresh->forceFill(['result' => $result->toArray()])->save();

            $this->setRawAttributes($fresh->getAttributes(), true);

            return $result;
        });
    }

    // ------------------------------------------------------------------ Batch
    public function batch(): ?Batch
    {
        return $this->batch_id ? Bus::findBatch($this->batch_id) : null;
    }

    /**
     * Stand aus dem Batch uebernehmen. Gibt sich selbst zurueck.
     *
     * Zwei Eigenheiten von Laravels Batch, die hier abgefangen werden:
     *
     * 1. Ein endgueltig fehlgeschlagener Job verringert pending_jobs NICHT.
     *    Deshalb setzt Laravel finished_at nie, sobald etwas fehlgeschlagen
     *    ist - $batch->finished() bleibt fuer immer false. Fertig ist der
     *    Lauf, wenn pending minus failed aufgeht (dieselbe Rechnung, mit der
     *    Laravel intern seinen finally-Callback ausloest).
     *
     * 2. Der Zaehler failed_jobs wird bei jedem Fehlschlag hochgezaehlt,
     *    failed_job_ids dagegen eindeutig gefuehrt. Nach queue:retry laufen
     *    die beiden auseinander. Gezaehlt wird deshalb ueber die IDs - ein
     *    Job, der dreimal scheitert, bleibt ein fehlgeschlagener Job.
     *
     * Wiederholungen innerhalb von tries zaehlen ohnehin nicht mit: der
     * Worker legt den Job zurueck in die Queue und meldet erst nach dem
     * letzten Versuch einen Fehlschlag.
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
            // finishedAt fehlt, sobald etwas fehlgeschlagen ist - siehe oben.
            $this->finished_at = $batch->finishedAt ?? Carbon::now();
        }

        if ($this->status === JobStatus::Failed && ! $this->message) {
            $this->message = "{$this->failed} von {$this->total} Aufgaben fehlgeschlagen.";
        }

        if ($this->isDirty()) {
            $this->save();
        }

        return $this;
    }

    /** Alle Aufgaben durch - erfolgreiche wie endgueltig fehlgeschlagene. */
    public function isBatchDone(Batch $batch): bool
    {
        return $batch->totalJobs > 0 && ($batch->pendingJobs - $batch->failedJobs) <= 0;
    }

    public function statusOf(Batch $batch): JobStatus
    {
        $done = $this->isBatchDone($batch);

        return match (true) {
            $batch->cancelled()                        => JobStatus::Cancelled,
            $done && count($batch->failedJobIds) > 0   => JobStatus::Failed,
            $done                                      => JobStatus::Finished,
            $batch->processedJobs() > 0 || $batch->failedJobs > 0 => JobStatus::Processing,
            default                                    => JobStatus::Pending,
        };
    }

    public function progress(): int
    {
        if ($this->total <= 0) {
            return $this->status->isOpen() ? 0 : 100;
        }

        // Erledigt ist erledigt - auch ein fehlgeschlagener Job ist durch.
        return min(100, (int) floor((($this->processed + $this->failed) / $this->total) * 100));
    }

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

    /** Genau die Felder aus der Doku - nichts Variables. */
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
