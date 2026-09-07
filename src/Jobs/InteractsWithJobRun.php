<?php

namespace Didasto\RestApi\Jobs;

use Closure;
use RuntimeException;

/**
 * In jeden Job der Kette einbinden. Der Controller haengt die Lauf-Nummer
 * beim Anstossen an - im Job selbst ist nichts dafuer zu tun.
 *
 *   class FindeAsinJob implements ShouldQueue
 *   {
 *       use Batchable, Queueable, InteractsWithJobRun;
 *
 *       public function handle(): void
 *       {
 *           $suche = $this->data()->suchbegriff;
 *
 *           $this->updateResult(function (ProduktSucheResult $result) use ($asin) {
 *               $result->asin = $asin;
 *           });
 *       }
 *   }
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
            throw new RuntimeException(static::class.': dieser Job gehoert zu keinem Lauf.');
        }

        return JobRun::query()->findOrFail($this->jobRunId);
    }

    /** Die Eingangsdaten - fuer alle Jobs der Kette dieselben. */
    public function data(): ?JobData
    {
        return $this->run()->data();
    }

    /** Der Stand des Ergebnisses, wie ihn die Vorgaenger hinterlassen haben. */
    public function result(): ?JobResult
    {
        return $this->run()->result();
    }

    /**
     * Das Ergebnis fortschreiben. Laeuft unter einer Zeilensperre, damit
     * gleichzeitig laufende Jobs sich nicht gegenseitig ueberschreiben -
     * jeder sieht beim Schreiben den aktuellen Stand.
     */
    public function updateResult(Closure $mutator): JobResult
    {
        return $this->run()->updateResult($mutator);
    }
}
