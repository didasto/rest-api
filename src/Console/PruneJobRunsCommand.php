<?php

namespace Didasto\RestApi\Console;

use Didasto\RestApi\Jobs\JobRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneJobRunsCommand extends Command
{
    protected $signature = 'rest-api:prune-jobs {--days= : Laeufe aelter als X Tage entfernen}';

    protected $description = 'Abgeschlossene Job-Laeufe aufraeumen.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('rest-api.jobs.prune', 30));

        if ($days <= 0) {
            $this->info('Aufraeumen ist abgeschaltet (jobs.prune = 0).');

            return self::SUCCESS;
        }

        $removed = JobRun::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', Carbon::now()->subDays($days))
            ->delete();

        $this->info("{$removed} Lauf/Laeufe aelter als {$days} Tage entfernt.");

        return self::SUCCESS;
    }
}
