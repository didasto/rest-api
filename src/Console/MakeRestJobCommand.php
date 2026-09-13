<?php

namespace Didasto\RestApi\Console;

use Illuminate\Support\Str;

use function Laravel\Prompts\text;

/**
 * Generates a job API: the controller, its request, the typed input and
 * a first job. POST starts a run, GET reports it, DELETE cancels it.
 */
class MakeRestJobCommand extends StubCommand
{
    protected $signature = 'make:rest-job
        {name? : Base name of the run, for example ImportMembers}
        {--result : Write a JobResult as well}
        {--path= : Target directory for the controller, overrides the configuration}
        {--force : Overwrite existing files}';

    protected $description = 'Create a job API: controller, request, input object and a first job.';

    public function handle(): int
    {
        $name = $this->argument('name') ?: text(
            label: 'What is the run called?',
            placeholder: 'ImportMembers',
            required: true,
        );

        $base      = $this->baseName($name);
        $directory = $this->controllerDirectory();

        $this->warnWhenUnscanned($directory);

        $key       = Str::kebab($base);
        $jobsDir   = $this->laravel->path('Jobs/'.$base);
        $jobsNs    = $this->namespaceFor($jobsDir);
        $requests  = $this->requestDirectory();
        $requestNs = $this->namespaceFor($requests);

        $written = $this->request($base, $requestNs, $requests)
            && $this->data($base, $jobsNs, $jobsDir)
            && (! $this->option('result') || $this->result($base, $jobsNs, $jobsDir))
            && $this->job($base, $jobsNs, $jobsDir)
            && $this->controller($base, $key, $directory, $jobsNs, $requestNs);

        if (! $written) {
            return self::FAILURE;
        }

        $this->components->info("POST /{$key} starts a run, GET /{$key}/{id} reports it.");

        return self::SUCCESS;
    }

    public function request(string $base, string $namespace, string $directory): bool
    {
        return $this->write($directory.'/'.$base.'Request.php', $this->render('request.job.stub', [
            'namespace' => $namespace,
            'class'     => $base.'Request',
            'dataClass' => $base.'Data',
        ]));
    }

    public function data(string $base, string $namespace, string $directory): bool
    {
        return $this->write($directory.'/'.$base.'Data.php', $this->render('job-data.stub', [
            'namespace' => $namespace,
            'class'     => $base.'Data',
        ]));
    }

    public function result(string $base, string $namespace, string $directory): bool
    {
        return $this->write($directory.'/'.$base.'Result.php', $this->render('job-result.stub', [
            'namespace' => $namespace,
            'class'     => $base.'Result',
        ]));
    }

    public function job(string $base, string $namespace, string $directory): bool
    {
        return $this->write($directory.'/'.$base.'Job.php', $this->render('job.stub', [
            'namespace'     => $namespace,
            'class'         => $base.'Job',
            'useStatements' => '',
            'resultClass'   => $this->option('result') ? $base.'Result' : 'JobResult',
        ]));
    }

    public function controller(
        string $base,
        string $key,
        string $directory,
        string $jobsNamespace,
        string $requestNamespace,
    ): bool {
        $use = [
            "use {$jobsNamespace}\\{$base}Data;",
            "use {$jobsNamespace}\\{$base}Job;",
            "use {$requestNamespace}\\{$base}Request;",
        ];

        if ($this->option('result')) {
            $use[] = "use {$jobsNamespace}\\{$base}Result;";
        }

        sort($use);

        return $this->write($directory.'/'.$base.'Controller.php', $this->render('controller.job.stub', [
            'namespace'     => $this->namespaceFor($directory),
            'class'         => $base.'Controller',
            'key'           => $key,
            'uri'           => 'jobs/'.$key,
            'useStatements' => implode("\n", $use)."\n",
            'requestClass'  => $base.'Request',
            'dataClass'     => $base.'Data',
            'resultClass'   => $this->option('result') ? $base.'Result::class' : 'null',
            'jobClass'      => $base.'Job',
        ]));
    }
}
