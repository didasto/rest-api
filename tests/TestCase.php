<?php

namespace Didasto\RestApi\Tests;

use Didasto\RestApi\RestApiServiceProvider;
use Didasto\RestApi\Tests\Fixtures\Article;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [RestApiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // The fixtures stand in for an application's controller directory.
        $app['config']->set('rest-api.directories', [
            __DIR__.'/Fixtures' => [
                'prefix'     => 'api',
                'middleware' => [],
                'patterns'   => ['*Controller.php'],
            ],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Testbench ships no application migrations, so the table Laravel
        // keeps its batches in has to be created here.
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120)->unique();
            $table->string('slug')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->decimal('price', 8, 2)->default(0);
            $table->boolean('published')->default(false);
            $table->string('secret')->nullable();
            $table->timestamps();
        });
    }

    protected function article(array $attributes = []): Article
    {
        static $counter = 0;

        $counter++;

        return Article::query()->create($attributes + [
            'title'     => "Article {$counter}",
            'slug'      => "article-{$counter}",
            'position'  => $counter,
            'price'     => 1.5 * $counter,
            'published' => true,
        ]);
    }
}
