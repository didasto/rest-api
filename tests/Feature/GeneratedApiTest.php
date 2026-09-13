<?php

namespace Didasto\RestApi\Tests\Feature;

use Didasto\RestApi\Routing\ResourceRegistrar;
use Didasto\RestApi\Tests\Fixtures\Article;
use Didasto\RestApi\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * The point of the generator: what it writes has to work without being
 * touched. So it is generated, loaded, routed and called here.
 */
class GeneratedApiTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('rest-api.directories', [
            $app->basePath('app/Generated') => [
                'prefix'     => 'api',
                'middleware' => [],
                'patterns'   => ['*Controller.php'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->app->basePath('app/Generated'));
        File::deleteDirectory($this->app->basePath('app/Http'));
        File::deleteDirectory($this->app->basePath('tests/Feature/Api'));

        parent::tearDown();
    }

    protected function generate(array $options = []): void
    {
        $this->artisan('make:rest-api', $options + [
            'name'    => 'Article',
            '--model' => Article::class,
        ])->assertSuccessful();

        foreach ([
            'app/Generated/Requests/ArticleIndexRequest.php',
            'app/Generated/Requests/ArticleStoreRequest.php',
            'app/Generated/Requests/ArticleUpdateRequest.php',
            'app/Generated/ArticleController.php',
        ] as $file) {
            $path = $this->app->basePath($file);

            if (File::exists($path)) {
                require_once $path;
            }
        }
    }

    public function test_the_generated_controller_serves_the_whole_resource(): void
    {
        $this->generate();

        $this->app->make(ResourceRegistrar::class)->register();

        // Routes added after boot are only in the name lookup once it is refreshed.
        Route::getRoutes()->refreshNameLookups();

        $this->assertTrue(class_exists('App\\Generated\\ArticleController'));
        $this->assertTrue(Route::has('articles.index'));
        $this->assertTrue(Route::has('articles.destroy'));

        $article = $this->article();

        $this->getJson('/api/articles')->assertOk()->assertJsonCount(1);
        $this->getJson("/api/articles/{$article->id}")->assertOk()->assertJsonPath('id', $article->id);

        $this->postJson('/api/articles', ['title' => 'Written by the generator', 'position' => 4, 'price' => 1])
            ->assertCreated();

        $this->deleteJson("/api/articles/{$article->id}")->assertNoContent();
    }

    public function test_the_rules_are_drafted_from_the_table(): void
    {
        $this->generate();

        $store = File::get($this->app->basePath('app/Generated/Requests/ArticleStoreRequest.php'));

        $this->assertStringContainsString("'title'     => 'required|string',", $store);
        $this->assertStringContainsString("'slug'      => 'nullable|string',", $store);
        $this->assertStringContainsString("'position'  => 'nullable|integer',", $store);
        $this->assertStringContainsString("'price'     => 'nullable|numeric',", $store);
        $this->assertStringContainsString("'published' => 'nullable|boolean',", $store);

        // The key, the timestamps and hidden fields are never asked of the caller.
        $this->assertStringNotContainsString("'id'", $store);
        $this->assertStringNotContainsString('created_at', $store);
        $this->assertStringNotContainsString("'secret'", $store);

        $update = File::get($this->app->basePath('app/Generated/Requests/ArticleUpdateRequest.php'));

        $this->assertStringContainsString("'title'     => 'sometimes|string',", $update);
        $this->assertStringContainsString("'slug'      => 'sometimes|nullable|string',", $update);
    }

    public function test_filters_are_only_written_when_asked_for(): void
    {
        $this->artisan('make:rest-api', [
            'name'    => 'Article',
            '--model' => Article::class,
        ])->assertSuccessful();

        $this->assertStringContainsString(
            "// 'title' => StringFilter::class,",
            File::get($this->app->basePath('app/Generated/Requests/ArticleIndexRequest.php')),
        );

        File::deleteDirectory($this->app->basePath('app/Http'));
        File::deleteDirectory($this->app->basePath('app/Generated'));

        $this->artisan('make:rest-api', [
            'name'      => 'Article',
            '--model'   => Article::class,
            '--filters' => true,
        ])->assertSuccessful();

        $index = File::get($this->app->basePath('app/Generated/Requests/ArticleIndexRequest.php'));

        $this->assertStringContainsString("'id' => IdFilter::class,", $index);
        $this->assertStringContainsString("'title' => StringFilter::class,", $index);
        $this->assertStringContainsString("'position' => NumericFilter::class,", $index);
        $this->assertStringContainsString("'published' => BooleanFilter::class,", $index);
        $this->assertStringContainsString("'created_at' => DateFilter::class,", $index);
        $this->assertStringContainsString('use Didasto\RestApi\Filters\Groups\IdFilter;', $index);

        // Hidden model fields are not offered as filters.
        $this->assertStringNotContainsString("'secret'", $index);
    }

    public function test_the_generated_files_are_valid_php(): void
    {
        $this->generate(['--filters' => true, '--test' => true]);

        foreach (File::allFiles($this->app->basePath('app')) as $file) {
            $output = [];
            exec('php -l '.escapeshellarg($file->getPathname()).' 2>&1', $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));
        }
    }
}
