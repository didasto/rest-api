<?php

namespace Didasto\RestApi\Routing;

use Didasto\RestApi\Attributes\RestJob;
use Didasto\RestApi\Attributes\RestResource;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Finds controllers carrying #[RestResource] or #[RestJob] and registers
 * their routes.
 *
 * Hand written endpoints are still handled by the Spatie route
 * attributes. This registrar only deals with the generated ones, because
 * Laravel's apiResource can neither name the actions this way nor switch
 * them off one by one.
 */
class ResourceRegistrar
{
    /** action => [http verbs, takes a route parameter] */
    public array $map = [
        'index'   => [['GET'], false],
        'store'   => [['POST'], false],
        'show'    => [['GET'], true],
        'update'  => [['PUT', 'PATCH'], true],
        'destroy' => [['DELETE'], true],
    ];

    public function __construct(
        public Router $router,
        public array $config = [],
    ) {}

    public function register(): void
    {
        foreach ($this->config['directories'] ?? [] as $directory => $options) {
            if (is_numeric($directory)) {
                [$directory, $options] = [$options, []];
            }

            if (! is_dir($directory)) {
                continue;
            }

            $this->registerDirectory($directory, (array) $options);
        }
    }

    public function registerDirectory(string $directory, array $options): void
    {
        foreach ($this->classesIn($directory, $options['patterns'] ?? ['*.php']) as $class) {
            $resource = $this->attributeOf($class, RestResource::class);

            if ($resource instanceof RestResource) {
                $this->registerResource($class, $resource, $options);
            }

            $job = $this->attributeOf($class, RestJob::class);

            if ($job instanceof RestJob) {
                $this->registerJob($class, $job, $options);
            }
        }
    }

    /** @return array<int, class-string> */
    public function classesIn(string $directory, array $patterns): array
    {
        $finder = (new Finder())->files()->in($directory);

        foreach ($patterns as $pattern) {
            $finder->name($pattern);
        }

        $classes = [];

        foreach ($finder as $file) {
            $class = $this->classFromFile($file);

            if ($class && class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public function classFromFile(SplFileInfo $file): ?string
    {
        $contents = file_get_contents($file->getRealPath()) ?: '';

        preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace);
        preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $contents, $class);

        if (! isset($class[1])) {
            return null;
        }

        return isset($namespace[1]) ? trim($namespace[1]).'\\'.$class[1] : $class[1];
    }

    public function attributeOf(string $class, string $attribute): RestResource|RestJob|null
    {
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return null;
        }

        $attributes = $reflection->getAttributes($attribute);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    public function registerResource(string $class, RestResource $resource, array $options): void
    {
        $uri       = $resource->uri ?? $this->uriFor($resource->model);
        $name      = $resource->name ?? $uri;
        $parameter = $resource->parameter ?? $this->config['defaults']['parameter'] ?? 'id';
        $actions   = $this->assertKnown(
            $resource->actions($this->config['defaults']['actions'] ?? array_keys($this->map)),
            $class,
        );

        $this->router->group($this->groupFor($resource->middleware, $options), function () use (
            $class, $actions, $uri, $name, $parameter
        ) {
            foreach ($actions as $action) {
                [$verbs, $hasParameter] = $this->map[$action];

                $path = $hasParameter ? "{$uri}/{{$parameter}}" : $uri;

                $this->router
                    ->match($verbs, $path, [$class, $action])
                    ->name("{$name}.{$action}");
            }
        });
    }

    /**
     * Job API: POST starts a run, GET reports it, DELETE cancels - the
     * last one only when the attribute allows it.
     */
    public function registerJob(string $class, RestJob $job, array $options): void
    {
        $uri  = $job->uri ?? Str::kebab($job->key);
        $name = $job->name ?? $job->key;

        $this->router->group($this->groupFor($job->middleware, $options), function () use (
            $class, $job, $uri, $name
        ) {
            $this->router->post($uri, [$class, 'store'])->name("{$name}.store");
            $this->router->get("{$uri}/{id}", [$class, 'show'])->name("{$name}.show");

            if ($job->cancellable) {
                $this->router->delete("{$uri}/{id}", [$class, 'cancel'])->name("{$name}.cancel");
            }
        });
    }

    public function groupFor(array|string $middleware, array $options): array
    {
        return array_filter([
            'prefix'     => $options['prefix'] ?? null,
            'middleware' => array_merge((array) ($options['middleware'] ?? []), (array) $middleware),
        ]);
    }

    /**
     * An unknown action used to be skipped silently - the route was then
     * simply missing with nothing written anywhere. The most common cause
     * is a published config file from an older version of the package.
     *
     * @param  array<int, string>  $actions
     * @return array<int, string>
     */
    public function assertKnown(array $actions, string $class): array
    {
        $unknown = array_diff($actions, array_keys($this->map));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                "%s: unknown action%s %s. Allowed: %s. If the name comes from a ".
                'published config/rest-api.php, that file is probably older than the package.',
                $class,
                count($unknown) === 1 ? '' : 's',
                "'".implode("', '", $unknown)."'",
                implode(', ', array_keys($this->map)),
            ));
        }

        return $actions;
    }

    /** Member becomes members. Pluralisation is English only, so pass uri: for anything else. */
    public function uriFor(string $model): string
    {
        return Str::kebab(Str::pluralStudly(class_basename($model)));
    }
}
