<?php

namespace Didasto\RestApi\Console;

use Didasto\RestApi\Jobs\JobRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneJobRunsCommand extends Command
{
    protected $signature = 'rest-api:prune-jobs {--days= : Remove runs finished more than this many days ago}';

    protected $description = 'Remove finished job runs.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('rest-api.jobs.prune', 30));

        if ($days <= 0) {
            $this->info('Pruning is disabled (jobs.prune = 0).');

            return self::SUCCESS;
        }

        $removed = JobRun::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', Carbon::now()->subDays($days))
            ->delete();

        $this->info("Removed {$removed} run(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
