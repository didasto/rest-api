<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Didasto\RestApi\Jobs\InteractsWithJobRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * First job of the run: appends one CreateArticleJob per requested row to
 * the very same batch, which is what makes total grow while the run is
 * already going.
 */
class FanOutJob implements ShouldQueue
{
    use Batchable, InteractsWithJobRun, Queueable;

    public function handle(): void
    {
        /** @var ReportData $data */
        $data = $this->data();
        $run  = $this->run()->id;

        $jobs = [];

        for ($position = 1; $position <= $data->count; $position++) {
            $jobs[] = (new CreateArticleJob("{$data->prefix}-{$run}-{$position}", $position))
                ->forJobRun($run);
        }

        $this->batch()?->add($jobs);
    }
}
