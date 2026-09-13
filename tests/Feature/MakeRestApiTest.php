<?php

namespace Didasto\RestApi\Tests\Feature;

use Didasto\RestApi\Tests\TestCase;
use Illuminate\Support\Facades\File;

/** What the generator writes, and what it refuses to write. */
class MakeRestApiTest extends TestCase
{
    protected function target(): string
    {
        return $this->app->basePath('app/Http/Controllers/Api');
    }

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
        File::deleteDirectory($this->app->basePath('tests/Feature/Api'));
        File::deleteDirectory($this->app->basePath('stubs/rest-api'));

        parent::tearDown();
    }

    protected function contents(string $path): string
    {
        $full = $this->app->basePath($path);

        $this->assertFileExists($full);

        return File::get($full);
    }

    public function test_it_writes_a_controller_and_its_requests(): void
    {
        $this->artisan('make:rest-api', ['name' => 'Member', '--model' => 'Member'])
            ->assertSuccessful();

        $controller = $this->contents('app/Http/Controllers/Api/MemberController.php');

        $this->assertStringContainsString('namespace App\Http\Controllers\Api;', $controller);
        $this->assertStringContainsString('#[RestResource(model: Member::class)]', $controller);
        $this->assertStringContainsString("'index'  => MemberIndexRequest::class,", $controller);
        $this->assertStringContainsString("'store'  => MemberStoreRequest::class,", $controller);
        $this->assertStringContainsString("'update' => MemberUpdateRequest::class,", $controller);

        $this->assertFileExists($this->app->basePath('app/Http/Requests/Api/MemberIndexRequest.php'));
        $this->assertFileExists($this->app->basePath('app/Http/Requests/Api/MemberStoreRequest.php'));
        $this->assertFileExists($this->app->basePath('app/Http/Requests/Api/MemberUpdateRequest.php'));

        // show and destroy need no request class.
        $this->assertFileDoesNotExist($this->app->basePath('app/Http/Requests/Api/MemberShowRequest.php'));
    }

    public function test_a_controller_suffix_in_the_name_is_not_doubled(): void
    {
        $this->artisan('make:rest-api', ['name' => 'MemberController', '--model' => 'Member'])
            ->assertSuccessful();

        $this->assertFileExists($this->app->basePath('app/Http/Controllers/Api/MemberController.php'));
        $this->assertFileDoesNotExist(
            $this->app->basePath('app/Http/Controllers/Api/MemberControllerController.php'),
        );
    }

    public function test_read_only_writes_only_the_reading_actions(): void
    {
        $this->artisan('make:rest-api', ['name' => 'Member', '--model' => 'Member', '--read-only' => true])
            ->assertSuccessful();

        $controller = $this->contents('app/Http/Controllers/Api/MemberController.php');

        $this->assertStringContainsString("only: ['index', 'show']", $controller);
        $this->assertStringNotContainsString('StoreRequest', $controller);
        $this->assertFileDoesNotExist($this->app->basePath('app/Http/Requests/Api/MemberStoreRequest.php'));
    }

    public function test_except_is_written_as_except(): void
    {
        $this->artisan('make:rest-api', ['name' => 'Member', '--model' => 'Member', '--except' => 'destroy'])
            ->assertSuccessful();

        $this->assertStringContainsString(
            "except: ['destroy']",
            $this->contents('app/Http/Controllers/Api/MemberController.php'),
        );
    }

    public function test_only_and_except_together_are_refused(): void
    {
        $this->artisan('make:rest-api', [
            'name'     => 'Member',
            '--model'  => 'Member',
            '--only'   => 'index',
            '--except' => 'destroy',
        ])->assertFailed();

        $this->assertFileDoesNotExist($this->app->basePath('app/Http/Controllers/Api/MemberController.php'));
    }

    public function test_read_only_cannot_be_combined_with_only(): void
    {
        $this->artisan('make:rest-api', [
            'name'        => 'Member',
            '--model'     => 'Member',
            '--only'      => 'index',
            '--read-only' => true,
        ])->assertFailed();
    }

    public function test_an_unknown_action_names_the_known_ones(): void
    {
        $this->artisan('make:rest-api', ['name' => 'Member', '--model' => 'Member', '--only' => 'list'])
            ->expectsOutputToContain('Allowed: index, show, store, update, destroy.')
            ->assertFailed();
    }

    public function test_existing_files_are_kept_unless_forced(): void
    {
        $this->artisan('make:rest-api', ['name' => 'Member', '--model' => 'Member'])->assertSuccessful();

        File::put($this->app->basePath('app/Http/Controllers/Api/MemberController.php'), '<?php // mine');

        $this->artisan('make:rest-api', ['name' => 'Member', '--model' => 'Member'])->assertFailed();

        $this->assertSame('<?php // mine', $this->contents('app/Http/Controllers/Api/MemberController.php'));

        $this->artisan('make:rest-api', ['name' => 'Member', '--model' => 'Member', '--force' => true])
            ->assertSuccessful();

        $this->assertStringContainsString('RestResource', $this->contents('app/Http/Controllers/Api/MemberController.php'));
    }

    public function test_without_a_table_the_rules_stay_empty(): void
    {
        $this->artisan('make:rest-api', ['name' => 'Member', '--model' => 'Member'])->assertSuccessful();

        $this->assertStringContainsString(
            'No table found - add the rules by hand.',
            $this->contents('app/Http/Requests/Api/MemberStoreRequest.php'),
        );
    }

    public function test_a_directory_outside_the_configuration_is_reported(): void
    {
        $this->artisan('make:rest-api', [
            'name'    => 'Member',
            '--model' => 'Member',
            '--path'  => 'app/Elsewhere',
        ])
            ->expectsOutputToContain('not listed in rest-api.directories')
            ->assertSuccessful();

        $this->assertFileExists($this->app->basePath('app/Elsewhere/MemberController.php'));

        File::deleteDirectory($this->app->basePath('app/Elsewhere'));
    }

    public function test_a_published_stub_wins(): void
    {
        File::ensureDirectoryExists($this->app->basePath('stubs/rest-api'));
        File::put(
            $this->app->basePath('stubs/rest-api/controller.resource.stub'),
            "<?php\n\nnamespace {{ namespace }};\n\n// house style {{ class }}\n",
        );

        $this->artisan('make:rest-api', ['name' => 'Member', '--model' => 'Member'])->assertSuccessful();

        $this->assertStringContainsString(
            '// house style MemberController',
            $this->contents('app/Http/Controllers/Api/MemberController.php'),
        );
    }

    public function test_a_test_is_written_on_request(): void
    {
        $this->artisan('make:rest-api', ['name' => 'Member', '--model' => 'Member', '--test' => true])
            ->assertSuccessful();

        $test = $this->contents('tests/Feature/Api/MemberApiTest.php');

        $this->assertStringContainsString("getJson('/api/members')", $test);
    }

    public function test_without_a_model_a_custom_api_is_written(): void
    {
        $this->artisan('make:rest-api', ['name' => 'CashBook', '--actions' => 'close,balance'])
            ->assertSuccessful();

        $controller = $this->contents('app/Http/Controllers/Api/CashBookController.php');

        $this->assertStringContainsString("#[Prefix('cash-books')]", $controller);
        $this->assertStringContainsString("#[Post('close', name: 'cash-books.close')]", $controller);
        $this->assertStringContainsString("#[Get('balance', name: 'cash-books.balance')]", $controller);
        $this->assertStringContainsString('#[ApiOperation(summary:', $controller);
        $this->assertStringNotContainsString('RestResource', $controller);
    }

    public function test_a_custom_api_without_actions_gets_one_example(): void
    {
        $this->artisan('make:rest-api', ['name' => 'Health'])->assertSuccessful();

        $controller = $this->contents('app/Http/Controllers/Api/HealthController.php');

        $this->assertStringContainsString('public function __invoke()', $controller);
    }
}
