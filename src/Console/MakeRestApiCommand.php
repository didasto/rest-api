<?php

namespace Didasto\RestApi\Console;

use Illuminate\Support\Str;
use InvalidArgumentException;

use function Laravel\Prompts\text;

/**
 * Generates a REST API: the controller and the requests that belong to it.
 *
 * With --model a model resource, without one a hand written API. What is
 * generated is ordinary code - it can be edited, and every method of the
 * base classes may be overridden.
 */
class MakeRestApiCommand extends StubCommand
{
    protected $signature = 'make:rest-api
        {name? : Base name of the API, for example Member}
        {--model= : Model of the resource, App\\Models\\… or fully qualified}
        {--only= : Only these actions: index,show,store,update,destroy}
        {--except= : Every action but these}
        {--read-only : Short for --only=index,show}
        {--actions= : Custom API: method names, comma separated}
        {--filters : Suggest filters from the table columns}
        {--test : Write a feature test as well}
        {--path= : Target directory, overrides the configuration}
        {--force : Overwrite existing files}';

    protected $description = 'Create a REST API controller with its request classes.';

    public array $allActions = ['index', 'show', 'store', 'update', 'destroy'];

    public function handle(ColumnRules $columns): int
    {
        $name = $this->argument('name') ?: text(
            label: 'What is the API called?',
            placeholder: 'Member',
            required: true,
        );

        $base = $this->baseName($name);

        try {
            $actions = $this->actions();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $directory = $this->controllerDirectory();
        $this->warnWhenUnscanned($directory);

        $written = $this->option('model')
            ? $this->resource($base, $actions, $columns, $directory)
            : $this->custom($base, $directory);

        if (! $written) {
            return self::FAILURE;
        }

        $this->components->info('Run route:list to see the new routes.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------- Actions
    /** @return array<int, string> */
    public function actions(): array
    {
        $only     = $this->list($this->option('only'));
        $except   = $this->list($this->option('except'));
        $readOnly = (bool) $this->option('read-only');

        if ($only !== [] && $except !== []) {
            throw new InvalidArgumentException('Use either --only or --except, not both.');
        }

        if ($readOnly && ($only !== [] || $except !== [])) {
            throw new InvalidArgumentException('--read-only cannot be combined with --only or --except.');
        }

        if ($readOnly) {
            return ['index', 'show'];
        }

        $unknown = array_diff(array_merge($only, $except), $this->allActions);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                "Unknown action%s '%s'. Allowed: %s.",
                count($unknown) === 1 ? '' : 's',
                implode("', '", $unknown),
                implode(', ', $this->allActions),
            ));
        }

        if ($only !== []) {
            return array_values(array_intersect($this->allActions, $only));
        }

        return array_values(array_diff($this->allActions, $except));
    }

    /** @return array<int, string> */
    public function list(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    // ------------------------------------------------------ Model resource
    public function resource(string $base, array $actions, ColumnRules $columns, string $directory): bool
    {
        $model     = $this->qualifyModel((string) $this->option('model'));
        $namespace = $this->namespaceFor($directory);
        $requests  = $this->requestDirectory();
        $requestNs = $this->namespaceFor($requests);

        if (! class_exists($model)) {
            $this->components->warn("Model {$model} does not exist yet - generate it with make:model.");
        }

        $needed = $this->requestsFor($actions);
        $match  = [];
        $use    = [];
        $width  = $needed === [] ? 0 : max(array_map('strlen', $needed)) + 2;

        foreach ($needed as $action) {
            $class          = $base.Str::studly($action).'Request';
            $use[]          = "use {$requestNs}\\{$class};";
            $match[$action] = sprintf(
                "            %-{$width}s => {$class}::class,",
                "'{$action}'",
            );

            if (! $this->writeRequest($action, $class, $requestNs, $requests, $model, $columns)) {
                return false;
            }
        }

        sort($use);

        $contents = $this->render('controller.resource.stub', [
            'namespace'      => $namespace,
            'class'          => $base.'Controller',
            'model'          => class_basename($model),
            'modelNamespace' => $model,
            'actionArgument' => $this->actionArgument($actions),
            'useStatements'  => $use === [] ? '' : implode("\n", $use)."\n",
            'requestMatch'   => implode("\n", $match),
        ]);

        if (! $this->write($directory.'/'.$base.'Controller.php', $contents)) {
            return false;
        }

        return $this->option('test')
            ? $this->test($base, $model)
            : true;
    }

    /** index always gets one, store and update need one, show and destroy do not. */
    public function requestsFor(array $actions): array
    {
        return array_values(array_intersect(['index', 'store', 'update'], $actions));
    }

    public function actionArgument(array $actions): string
    {
        $missing = array_values(array_diff($this->allActions, $actions));

        if ($missing === []) {
            return '';
        }

        if (count($actions) <= count($missing)) {
            return ", only: ['".implode("', '", $actions)."']";
        }

        return ", except: ['".implode("', '", $missing)."']";
    }

    public function writeRequest(
        string $action,
        string $class,
        string $namespace,
        string $directory,
        string $model,
        ColumnRules $columns,
    ): bool {
        $path = $directory.'/'.$class.'.php';

        if ($action === 'index') {
            return $this->write($path, $this->indexRequest($class, $namespace, $model, $columns));
        }

        $rules = $columns->rules($model, partial: $action === 'update');

        return $this->write($path, $this->render("request.{$action}.stub", [
            'namespace' => $namespace,
            'class'     => $class,
            'rules'     => $this->ruleLines($rules),
        ]));
    }

    public function ruleLines(array $rules): string
    {
        if ($rules === []) {
            return "            // No table found - add the rules by hand.";
        }

        $width = max(array_map('strlen', array_keys($rules))) + 2;
        $lines = [];

        foreach ($rules as $field => $rule) {
            $lines[] = sprintf("            %-{$width}s => '%s',", "'{$field}'", $rule);
        }

        return implode("\n", $lines);
    }

    public function indexRequest(string $class, string $namespace, string $model, ColumnRules $columns): string
    {
        $filters  = $this->option('filters') ? $columns->filters($model) : [];
        $sortable = $this->option('filters') ? $columns->sortable($model) : [];
        $lines    = [];
        $groups   = [];

        foreach ($filters as $field => $group) {
            $groups[$group] = true;
            $lines[]        = "            '{$field}' => {$group}::class,";
        }

        $use = array_map(
            fn (string $group) => "use Didasto\\RestApi\\Filters\\Groups\\{$group};",
            array_keys($groups),
        );

        return $this->render('request.index.stub', [
            'namespace'     => $namespace,
            'class'         => $class,
            'useStatements' => $use === [] ? '' : implode("\n", $use)."\n",
            'filters'       => $lines === []
                ? "            // 'title' => StringFilter::class,"
                : implode("\n", $lines),
            'sortable'      => $sortable === []
                ? ''
                : "'".implode("', '", $sortable)."'",
        ]);
    }

    // --------------------------------------------------------- Custom API
    public function custom(string $base, string $directory): bool
    {
        $methods = $this->list($this->option('actions'));
        $uri     = Str::kebab(Str::pluralStudly($base));
        $bodies  = [];
        $verbs   = [];

        foreach ($methods === [] ? ['__invoke'] : $methods as $method) {
            $verb            = $this->verbFor($method);
            $verbs[$verb]    = true;
            $bodies[]        = $this->render('method.custom.stub', [
                'verb'      => $verb,
                'path'      => $method === '__invoke' ? '' : Str::kebab($method),
                'routeName' => $uri.'.'.Str::kebab($method === '__invoke' ? 'index' : $method),
                'summary'   => Str::headline($method),
                'tag'       => Str::headline($base),
                'method'    => $method,
                'signature' => '',
            ]);
        }

        $use = array_map(
            fn (string $verb) => "use Spatie\\RouteAttributes\\Attributes\\{$verb};",
            array_keys($verbs),
        );
        $use[] = 'use Didasto\\RestApi\\Attributes\\ApiOperation;';
        sort($use);

        $contents = $this->render('controller.custom.stub', [
            'namespace'     => $this->namespaceFor($directory),
            'class'         => $base.'Controller',
            'uri'           => $uri,
            'useStatements' => implode("\n", $use)."\n",
            'methods'       => implode("\n\n", $bodies),
        ]);

        return $this->write($directory.'/'.$base.'Controller.php', $contents);
    }

    /** A name that reads like a write becomes a POST, everything else a GET. */
    public function verbFor(string $method): string
    {
        $writing = ['store', 'create', 'close', 'open', 'reopen', 'send', 'import', 'export', 'sync', 'run'];

        return in_array(Str::camel($method), $writing, true) ? 'Post' : 'Get';
    }

    // --------------------------------------------------------------- Tests
    public function test(string $base, string $model): bool
    {
        $directory = $this->laravel->basePath('tests/Feature/Api');
        $uri       = Str::kebab(Str::pluralStudly(class_basename($model)));
        $prefix    = trim((string) (config('rest-api.directories.'.$this->controllerDirectory().'.prefix') ?? 'api'), '/');

        return $this->write($directory.'/'.$base.'ApiTest.php', $this->render('test.resource.stub', [
            'namespace'     => 'Tests\\Feature\\Api',
            'class'         => $base.'ApiTest',
            'route'         => '/'.trim($prefix.'/'.$uri, '/'),
            'documentation' => (string) config('rest-api.openapi.route', '/api/doc'),
            'tag'           => class_basename($model),
        ]));
    }
}
