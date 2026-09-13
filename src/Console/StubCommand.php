<?php

namespace Didasto\RestApi\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared ground of the generators: where a stub comes from, where a file
 * goes, and what a class is called there.
 *
 * Every method may be overridden. A published stub in stubs/rest-api
 * always wins over the one shipped with the package, so a project can
 * generate code in its own house style without touching the package.
 */
abstract class StubCommand extends Command
{
    public array $written = [];

    public function files(): Filesystem
    {
        return $this->laravel->make(Filesystem::class);
    }

    // --------------------------------------------------------------- Stubs
    /** A published stub wins over the one of the package. */
    public function stubPath(string $name): string
    {
        $published = $this->laravel->basePath('stubs/rest-api/'.$name);

        if ($this->files()->exists($published)) {
            return $published;
        }

        return __DIR__.'/../../stubs/'.$name;
    }

    /** @param array<string, string> $replacements */
    public function render(string $stub, array $replacements): string
    {
        $contents = $this->files()->get($this->stubPath($stub));

        foreach ($replacements as $key => $value) {
            $contents = str_replace('{{ '.$key.' }}', $value, $contents);
        }

        return $contents;
    }

    // --------------------------------------------------------------- Files
    /** Returns false when the file exists and --force was not given. */
    public function write(string $path, string $contents): bool
    {
        $files = $this->files();

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->error('Already there: '.$this->relative($path).' (use --force to overwrite)');

            return false;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $contents);

        $this->written[] = $path;
        $this->components->info('Written: '.$this->relative($path));

        return true;
    }

    public function relative(string $path): string
    {
        return Str::after($path, $this->laravel->basePath().DIRECTORY_SEPARATOR);
    }

    // ---------------------------------------------------------- Namespaces
    /** app/Http/Controllers/Api => App\Http\Controllers\Api */
    public function namespaceFor(string $directory): string
    {
        $relative = Str::after(
            $this->normalise($directory),
            $this->normalise($this->laravel->path()).'/',
        );

        $root = trim($this->laravel->getNamespace(), '\\');

        if ($relative === $this->normalise($directory)) {
            // Outside of app/ - fall back to the path below the project root.
            $relative = Str::after($relative, $this->normalise($this->laravel->basePath()).'/');
        }

        return $root.'\\'.str_replace('/', '\\', trim($relative, '/'));
    }

    public function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * The directory generated controllers go into: --path, otherwise the
     * first entry of rest-api.directories.
     */
    public function controllerDirectory(): string
    {
        $path = $this->option('path');

        if ($path) {
            return $this->laravel->basePath($path);
        }

        $directories = (array) config('rest-api.directories', []);
        $first       = array_key_first($directories);

        if ($first === null) {
            throw new RuntimeException('rest-api.directories is empty - pass --path.');
        }

        return is_numeric($first) ? $directories[$first] : $first;
    }

    /** Requests live next to the controllers: Http/Controllers/Api => Http/Requests/Api */
    public function requestDirectory(): string
    {
        $controllers = $this->normalise($this->controllerDirectory());

        return str_contains($controllers, '/Controllers')
            ? str_replace('/Controllers', '/Requests', $controllers)
            : $controllers.'/Requests';
    }

    /**
     * A controller outside of every configured directory is never routed.
     * That is worth a word rather than a silent surprise.
     */
    public function warnWhenUnscanned(string $directory): void
    {
        $configured = array_map(
            fn ($key, $value) => $this->normalise(is_numeric($key) ? $value : $key),
            array_keys((array) config('rest-api.directories', [])),
            array_values((array) config('rest-api.directories', [])),
        );

        foreach ($configured as $candidate) {
            if (str_starts_with($this->normalise($directory), $candidate)) {
                return;
            }
        }

        $this->components->warn(
            'This directory is not listed in rest-api.directories, so no routes '.
            'will be registered for it.'
        );
    }

    // --------------------------------------------------------------- Names
    public function baseName(string $name): string
    {
        return Str::studly(Str::replaceLast('Controller', '', class_basename(str_replace('/', '\\', $name))));
    }

    /** User => App\Models\User, respecting a fully qualified name. */
    public function qualifyModel(string $model): string
    {
        $model = ltrim(str_replace('/', '\\', $model), '\\');
        $root  = trim($this->laravel->getNamespace(), '\\');

        if (str_starts_with($model, $root.'\\')) {
            return $model;
        }

        if (str_contains($model, '\\')) {
            return $model;
        }

        return class_exists($root.'\\Models\\'.$model)
            ? $root.'\\Models\\'.$model
            : $root.'\\'.$model;
    }
}
