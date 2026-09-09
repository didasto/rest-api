<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Didasto\RestApi\Attributes\RestJob;
use Didasto\RestApi\Http\Controllers\JobController;
use Didasto\RestApi\Jobs\JobData;
use Didasto\RestApi\Jobs\JobRun;
use Didasto\RestApi\Jobs\JobStatus;

#[RestJob(key: 'report', uri: 'jobs/report', cancellable: true)]
class ReportController extends JobController
{
    protected ?string $storeRequest = ReportRequest::class;

    protected ?string $dataClass = ReportData::class;

    protected ?string $resultClass = ReportResult::class;

    public function jobs(?JobData $data): array
    {
        return [new FanOutJob()];
    }

    public function resultUrl(JobRun $run): ?string
    {
        if ($run->status->isOpen()) {
            return null;
        }

        /** @var ReportResult $result */
        $result = $run->result();

        return $result->articleIds
            ? '/api/articles?filter[id][in]='.implode(',', $result->articleIds)
            : null;
    }
}
