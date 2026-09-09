<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Didasto\RestApi\Jobs\InteractsWithJobRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class CreateArticleJob implements ShouldQueue
{
    use Batchable, InteractsWithJobRun, Queueable;

    public int $tries = 1;

    public function __construct(
        public string $title,
        public int $position,
    ) {}

    public function handle(): void
    {
        /** @var ReportData $data */
        $data = $this->data();

        if ($data->failEvery && $this->position % $data->failEvery === 0) {
            throw new RuntimeException("Deliberate failure at {$this->title}.");
        }

        $article = Article::query()->create([
            'title'    => $this->title,
            'position' => $this->position,
        ]);

        $this->updateResult(function (ReportResult $result) use ($article) {
            $result->created    = ($result->created ?? 0) + 1;
            $result->articleIds = array_merge($result->articleIds ?? [], [$article->id]);
        });
    }
}
