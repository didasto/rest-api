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
 * Sucht Controller mit #[RestResource] und legt deren Routen an.
 *
 * Die handgeschriebenen Endpunkte macht weiterhin Spatie - dieser
 * Registrar kuemmert sich nur um die Model-Ressourcen, weil Laravels
 * apiResource weder list/show/store/update/delete benennen noch einzelne
 * Aktionen so feingranular abschalten kann.
 */
class ResourceRegistrar
{
    /** Aktion => [HTTP-Verben, hat Parameter] */
    public array $map = [
        'index'  => [['GET'], false],
        'store'  => [['POST'], false],
        'show'   => [['GET'], true],
        'update' => [['PUT', 'PATCH'], true],
        'destroy'=> [['DELETE'], true],
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

            if ($resource) {
                $this->registerResource($class, $resource, $options);
            }

            $job = $this->attributeOf($class, RestJob::class);

            if ($job) {
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

    public function resourceOf(string $class): ?RestResource
    {
        $attribute = $this->attributeOf($class, RestResource::class);

        return $attribute instanceof RestResource ? $attribute : null;
    }

    /**
     * Job-API: POST stoesst an, GET liefert den Stand, DELETE bricht ab -
     * letzteres nur, wenn das Attribut es erlaubt.
     */
    public function registerJob(string $class, RestJob $job, array $options): void
    {
        $uri  = $job->uri ?? Str::kebab($job->key);
        $name = $job->name ?? $job->key;

        $group = array_filter([
            'prefix'     => $options['prefix'] ?? null,
            'middleware' => array_merge(
                (array) ($options['middleware'] ?? []),
                (array) $job->middleware,
            ),
        ]);

        $this->router->group($group, function () use ($class, $job, $uri, $name) {
            $this->router->post($uri, [$class, 'store'])->name("{$name}.store");
            $this->router->get("{$uri}/{id}", [$class, 'show'])->name("{$name}.show");

            if ($job->cancellable) {
                $this->router->delete("{$uri}/{id}", [$class, 'cancel'])->name("{$name}.cancel");
            }
        });
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

        $group = array_filter([
            'prefix'     => $options['prefix'] ?? null,
            'middleware' => array_merge(
                (array) ($options['middleware'] ?? []),
                (array) $resource->middleware,
            ),
        ]);

        $this->router->group($group, function () use ($class, $actions, $uri, $name, $parameter) {
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
     * Eine unbekannte Aktion wurde frueher still uebersprungen - die Route
     * fehlte dann einfach, ohne dass irgendwo etwas stand. Haeufigste
     * Ursache: eine publizierte config/rest-api.php aus einer aelteren
     * Version des Packages.
     *
     * @param  array<int, string>  $actions
     * @return array<int, string>
     */
    public function assertKnown(array $actions, string $class): array
    {
        $unbekannt = array_diff($actions, array_keys($this->map));

        if ($unbekannt !== []) {
            throw new InvalidArgumentException(sprintf(
                "%s: unbekannte Aktion%s %s. Erlaubt sind: %s. ".
                'Steht der Name in einer publizierten config/rest-api.php, '.
                'ist sie vermutlich aelter als das Package.',
                $class,
                count($unbekannt) === 1 ? '' : 'en',
                "'".implode("', '", $unbekannt)."'",
                implode(', ', array_keys($this->map)),
            ));
        }

        return $actions;
    }

    /** Mitglied => mitglieder waere schoen, geht aber nur auf Englisch. */
    public function uriFor(string $model): string
    {
        return Str::kebab(Str::pluralStudly(class_basename($model)));
    }
}
