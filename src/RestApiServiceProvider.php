<?php

namespace Didasto\RestApi;

use Didasto\RestApi\Console\PruneJobRunsCommand;
use Didasto\RestApi\OpenApi\DocumentationController;
use Didasto\RestApi\OpenApi\Generator;
use Didasto\RestApi\OpenApi\ModelSchema;
use Didasto\RestApi\OpenApi\RuleMapper;
use Didasto\RestApi\Routing\ResourceRegistrar;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class RestApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rest-api.php', 'rest-api');

        $this->app->bind(RuleMapper::class);
        $this->app->bind(ModelSchema::class);

        $this->app->bind(Generator::class, fn ($app) => new Generator(
            router: $app['router'],
            mapper: $app->make(RuleMapper::class),
            config: $app['config']['rest-api'],
            models: $app->make(ModelSchema::class),
        ));

        $this->app->bind(ResourceRegistrar::class, fn ($app) => new ResourceRegistrar(
            router: $app['router'],
            config: $app['config']['rest-api'],
        ));
    }

    public function boot(Router $router): void
    {
        $this->publishes([
            __DIR__.'/../config/rest-api.php' => config_path('rest-api.php'),
        ], 'rest-api-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneJobRunsCommand::class]);
        }

        // With cached routes there is nothing to register - doing it anyway
        // would list every route twice.
        if (! $this->app->routesAreCached()) {
            $this->app->make(ResourceRegistrar::class)->register();
            $this->registerDocumentationRoute($router);
        }
    }

    public function registerDocumentationRoute(Router $router): void
    {
        $uri = config('rest-api.openapi.route');

        if (! $uri) {
            return;
        }

        $router->get($uri, DocumentationController::class)
            ->middleware(config('rest-api.openapi.middleware', []))
            ->name('rest-api.doc');
    }
}
