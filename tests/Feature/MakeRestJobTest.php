<?php

namespace Didasto\RestApi\Tests\Feature;

use Didasto\RestApi\Tests\TestCase;
use Illuminate\Support\Facades\File;

/** The generator for job APIs. */
class MakeRestJobTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('rest-api.directories', [
            $app->basePath('app/Http/Controllers/Api') => [
                'prefix'     => 'api',
                'middleware' => [],
                'patterns'   => ['*Controller.php'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->app->basePath('app/Http'));
        File::deleteDirectory($this->app->basePath('app/Jobs'));

        parent::tearDown();
    }

    protected function contents(string $path): string
    {
        $full = $this->app->basePath($path);

        $this->assertFileExists($full);

        return File::get($full);
    }

    public function test_it_writes_the_whole_chain(): void
    {
        $this->artisan('make:rest-job', ['name' => 'ImportMembers'])->assertSuccessful();

        $controller = $this->contents('app/Http/Controllers/Api/ImportMembersController.php');

        $this->assertStringContainsString("#[RestJob(key: 'import-members', uri: 'jobs/import-members'", $controller);
        $this->assertStringContainsString('protected ?string $storeRequest = ImportMembersRequest::class;', $controller);
        $this->assertStringContainsString('protected ?string $dataClass = ImportMembersData::class;', $controller);
        $this->assertStringContainsString('protected ?string $resultClass = null;', $controller);
        $this->assertStringContainsString('new ImportMembersJob()', $controller);
        $this->assertStringContainsString('use App\Jobs\ImportMembers\ImportMembersJob;', $controller);

        $this->assertFileExists($this->app->basePath('app/Http/Requests/Api/ImportMembersRequest.php'));
        $this->assertFileExists($this->app->basePath('app/Jobs/ImportMembers/ImportMembersData.php'));
        $this->assertFileExists($this->app->basePath('app/Jobs/ImportMembers/ImportMembersJob.php'));

        // Without --result there is no result class.
        $this->assertFileDoesNotExist($this->app->basePath('app/Jobs/ImportMembers/ImportMembersResult.php'));
    }

    public function test_a_result_class_is_written_on_request(): void
    {
        $this->artisan('make:rest-job', ['name' => 'ImportMembers', '--result' => true])->assertSuccessful();

        $this->assertStringContainsString(
            'protected ?string $resultClass = ImportMembersResult::class;',
            $this->contents('app/Http/Controllers/Api/ImportMembersController.php'),
        );

        $this->assertStringContainsString(
            'extends JobResult',
            $this->contents('app/Jobs/ImportMembers/ImportMembersResult.php'),
        );

        $this->assertStringContainsString(
            'function (ImportMembersResult $result)',
            $this->contents('app/Jobs/ImportMembers/ImportMembersJob.php'),
        );
    }

    public function test_existing_files_are_kept_unless_forced(): void
    {
        $this->artisan('make:rest-job', ['name' => 'ImportMembers'])->assertSuccessful();
        $this->artisan('make:rest-job', ['name' => 'ImportMembers'])->assertFailed();
        $this->artisan('make:rest-job', ['name' => 'ImportMembers', '--force' => true])->assertSuccessful();
    }

    public function test_the_generated_files_are_valid_php(): void
    {
        $this->artisan('make:rest-job', ['name' => 'ImportMembers', '--result' => true])->assertSuccessful();

        foreach (File::allFiles($this->app->basePath('app')) as $file) {
            $output = [];
            exec('php -l '.escapeshellarg($file->getPathname()).' 2>&1', $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));
        }
    }
}
