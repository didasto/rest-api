<?php

namespace Didasto\RestApi\OpenApi;

use Didasto\RestApi\Attributes\ApiOperation;
use Didasto\RestApi\Attributes\ApiSchema;
use Didasto\RestApi\Attributes\RestJob;
use Didasto\RestApi\Attributes\RestResource;
use Didasto\RestApi\Http\Requests\RestRequest;
use Didasto\RestApi\Jobs\JobStatus;
use Didasto\RestApi\Query\FilterSet;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use stdClass;
use Throwable;

/**
 * Builds the OpenAPI document from the registered routes.
 *
 * Sources: #[RestResource] for model resources, #[RestJob] for job APIs,
 * #[ApiOperation] for hand written endpoints, and in every case the rules
 * and filters of the request classes.
 *
 * Every method is an extension point. The ones worth knowing about:
 *
 *   routes()             which routes end up in the document
 *   resourceOperation()  how a resource action is described
 *   filterParameters()   how filters are presented
 *   schemaFor()          where the fields of a resource come from
 *   secure()             which routes require a token
 */
class Generator
{
    public array $schemas = [];

    public function __construct(
        public Router $router,
        public RuleMapper $mapper,
        public array $config = [],
        public ?ModelSchema $models = null,
    ) {
        $this->models ??= new ModelSchema();
    }

    public function generate(): array
    {
        $paths = [];

        foreach ($this->routes() as $route) {
            foreach ($this->operationsFor($route) as $method => $operation) {
                $paths[$this->path($route)][$method] = $operation;
            }
        }

        ksort($paths);

        return $this->asObjects(array_filter([
            'openapi'    => '3.1.0',
            'info'       => $this->config['openapi']['info'] ?? ['title' => 'API', 'version' => '1.0.0'],
            'servers'    => $this->config['openapi']['servers'] ?? null,
            'paths'      => $paths,
            'components' => array_filter([
                'schemas'         => $this->schemas,
                'securitySchemes' => $this->config['openapi']['security']['schemes'] ?? null,
            ]),
        ]));
    }

    /**
     * An empty properties must be {} in JSON, not [] - otherwise the
     * document is invalid and code generators bail out.
     */
    public function asObjects(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if (in_array($key, ['properties', 'schemas', 'headers', 'responses'], true) && $value === []) {
                $node[$key] = new stdClass();

                continue;
            }

            $node[$key] = $this->asObjects($value);
        }

        return $node;
    }

    // -------------------------------------------------------------- Routes
    /** @return array<int, Route> */
    public function routes(): array
    {
        $include = $this->config['openapi']['include'] ?? ['*'];
        $exclude = $this->config['openapi']['exclude'] ?? [];

        $routes = [];

        foreach ($this->router->getRoutes() as $route) {
            $uri = $route->uri();

            if (! Str::is($include, $uri) || Str::is($exclude, $uri)) {
                continue;
            }

            if (! is_string($route->getActionName()) || ! str_contains($route->getActionName(), '@')) {
                continue;
            }

            $routes[] = $route;
        }

        return $routes;
    }

    public function path(Route $route): string
    {
        return '/'.ltrim(preg_replace('/\{(\w+)\??\}/', '{$1}', $route->uri()), '/');
    }

    /** @return array<string, array> lowercase http method => operation */
    public function operationsFor(Route $route): array
    {
        [$class, $method] = explode('@', $route->getActionName());

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return [];
        }

        $operation = $this->apiOperation($class, $method);

        if ($operation?->hidden) {
            return [];
        }

        $job = $this->attributeOf($class, RestJob::class);

        if ($job instanceof RestJob) {
            return $this->jobOperations($route, $class, $method, $job, $operation);
        }

        $resource   = $this->restResource($class);
        $operations = [];

        foreach ($route->methods() as $verb) {
            if (in_array($verb, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $spec = $resource
                ? $this->resourceOperation($route, $class, $method, $verb, $resource, $operation)
                : $this->customOperation($route, $class, $method, $verb, $operation);

            $operations[strtolower($verb)] = $this->secure($spec, $route);
        }

        return $operations;
    }

    public function restResource(string $class): ?RestResource
    {
        $attribute = $this->attributeOf($class, RestResource::class);

        return $attribute instanceof RestResource ? $attribute : null;
    }

    public function attributeOf(string $class, string $attribute): ?object
    {
        $attributes = (new ReflectionClass($class))->getAttributes($attribute);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    public function apiOperation(string $class, string $method): ?ApiOperation
    {
        $attributes = (new ReflectionMethod($class, $method))->getAttributes(ApiOperation::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    // ------------------------------------------------------------ Security
    /**
     * Which middleware demands a token is configured once, so the route
     * stays the only source of this information.
     *
     * A parameter only becomes a scope for the middleware listed under
     * security.scopes_from. For role:treasurer that is what you want; for
     * auth:api the parameter names a guard, not a permission, and would
     * otherwise show up as a scope nobody can grant.
     */
    public function secure(array $spec, Route $route): array
    {
        $map = $this->config['openapi']['security']['middleware'] ?? [];

        if ($map === []) {
            return $spec;
        }

        $requirements = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            [$name, $arguments] = array_pad(explode(':', $middleware, 2), 2, null);

            $scheme = $map[$middleware] ?? $map[$name] ?? null;

            if (! $scheme) {
                continue;
            }

            $scopesFrom = $this->config['openapi']['security']['scopes_from'] ?? [];

            $scopes = $arguments !== null && in_array($name, $scopesFrom, true)
                ? array_map('trim', explode(',', $arguments))
                : [];

            $requirements[$scheme] = array_values(array_unique(array_merge(
                $requirements[$scheme] ?? [],
                $scopes,
            )));
        }

        if ($requirements === []) {
            return $spec;
        }

        $spec['security']  = [$requirements];
        $spec['responses'] = ($spec['responses'] ?? []) + [
            '401' => ['description' => 'Not authenticated, or the token is invalid'],
            '403' => ['description' => 'Authenticated, but missing the required permission'],
        ];

        return $spec;
    }

    // ----------------------------------------------------------- Resources
    public function resourceOperation(
        Route $route,
        string $class,
        string $action,
        string $verb,
        RestResource $resource,
        ?ApiOperation $operation,
    ): array {
        $name = class_basename($resource->model);
        $tag  = $operation?->tag ?? $resource->tag ?? $name;

        // Registers the schema in components as a side effect.
        $this->schemaFor($class, $resource);

        $spec = [
            'tags'        => [$tag],
            'operationId' => $route->getName() ?: Str::camel("{$name}_{$action}"),
            'summary'     => $operation?->summary ?? $this->summary($action, $name),
            'parameters'  => $this->pathParameters($route),
        ];

        if ($operation?->description) {
            $spec['description'] = $operation->description;
        }

        if ($action === 'index') {
            $spec['parameters'] = array_merge($spec['parameters'], $this->queryParameters($class));
            $spec['responses']  = [
                '200' => [
                    'description' => 'A page of results. Pagination is reported in the headers '.
                        'X-Total-Count, X-Page, X-Per-Page, X-Last-Page and Link.',
                    'headers' => $this->paginationHeaders(),
                    'content' => ['application/json' => ['schema' => [
                        'type'  => 'array',
                        'items' => ['$ref' => "#/components/schemas/{$name}"],
                    ]]],
                ],
                '422' => $this->queryError(),
            ];

            return array_filter($spec);
        }

        if (in_array($action, ['store', 'update'], true)) {
            $rules = $this->rulesFor($class, $action);

            if ($rules !== []) {
                $spec['requestBody'] = [
                    'required' => true,
                    'content'  => ['application/json' => ['schema' => $this->writable(
                        $this->mapper->toSchema($rules),
                        $resource->model,
                    )]],
                ];
            }

            // PUT and PATCH accept the same rules. An operationId may only
            // appear once in the document.
            if ($verb === 'PATCH') {
                $spec['operationId'] = ($spec['operationId'] ?? '').'Patch';
            }
        }

        $spec['responses'] = $this->responses($action, $name);

        return array_filter($spec);
    }

    public function summary(string $action, string $name): string
    {
        return match ($action) {
            'index'   => "List {$name} records",
            'show'    => "Show a {$name}",
            'store'   => "Create a {$name}",
            'update'  => "Update a {$name}",
            'destroy' => "Delete a {$name}",
            default   => Str::headline($action),
        };
    }

    public function responses(string $action, string $name): array
    {
        $body = ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$name}"]]]];

        return match ($action) {
            'store'   => ['201' => ['description' => 'Created'] + $body, '422' => $this->validationError()],
            'update'  => [
                '200' => ['description' => 'Updated'] + $body,
                '404' => ['description' => 'Not found'],
                '422' => $this->validationError(),
            ],
            'destroy' => ['204' => ['description' => 'Deleted'], '404' => ['description' => 'Not found']],
            default   => ['200' => ['description' => 'OK'] + $body, '404' => ['description' => 'Not found']],
        };
    }

    /** Unknown filter, unknown operator, or a column that may not be sorted by. */
    public function queryError(): array
    {
        return [
            'description' => 'Invalid query - unknown filter, operator or sort column',
            'content'     => ['application/json' => ['schema' => [
                'type'       => 'object',
                'properties' => ['message' => ['type' => 'string']],
            ]]],
        ];
    }

    public function validationError(): array
    {
        return [
            'description' => 'Validation failed',
            'content'     => ['application/json' => ['schema' => [
                'type'       => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'errors'  => ['type' => 'object', 'additionalProperties' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ]],
                ],
            ]]],
        ];
    }

    public function paginationHeaders(): array
    {
        return [
            'X-Total-Count' => ['schema' => ['type' => 'integer'], 'description' => 'Total number of matches'],
            'X-Page'        => ['schema' => ['type' => 'integer'], 'description' => 'Current page'],
            'X-Per-Page'    => ['schema' => ['type' => 'integer'], 'description' => 'Records per page'],
            'X-Last-Page'   => ['schema' => ['type' => 'integer'], 'description' => 'Last page'],
            'Link'          => ['schema' => ['type' => 'string'], 'description' => 'prev, next, first, last'],
        ];
    }

    // ---------------------------------------------------------------- Jobs
    /** @return array<string, array> */
    public function jobOperations(
        Route $route,
        string $class,
        string $action,
        RestJob $job,
        ?ApiOperation $operation,
    ): array {
        $tag = $operation?->tag ?? $job->tag ?? Str::headline($job->key);

        $this->schemas['JobRun'] ??= $this->jobSchema();

        $body = ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/JobRun']]]];

        $spec = [
            'tags'        => [$tag],
            'operationId' => $route->getName() ?: Str::camel($job->key.'_'.$action),
            'parameters'  => $this->pathParameters($route),
        ];

        if ($action === 'store') {
            $rules = $this->rulesFor($class, 'store');

            $spec['summary']     = $operation?->summary ?? $job->summary ?? Str::headline($job->key).' - start a run';
            $spec['description'] = $operation?->description
                ?? 'Creates a run and returns its id. The progress is then polled with GET on the '.
                   'same route plus the id; Retry-After suggests a sensible polling interval.';

            if ($rules !== []) {
                // A run has no model, so there is nothing to hide here.
                $spec['requestBody'] = [
                    'required' => true,
                    'content'  => ['application/json' => ['schema' => $this->mapper->toSchema($rules)]],
                ];
            }

            $spec['responses'] = [
                '202' => [
                    'description' => 'Accepted, run created',
                    'headers'     => [
                        'Location'    => ['schema' => ['type' => 'string'], 'description' => 'Route reporting the progress'],
                        'Retry-After' => ['schema' => ['type' => 'integer'], 'description' => 'Seconds until the next poll'],
                    ],
                ] + $body,
                '422' => $this->validationError(),
            ];
        }

        if ($action === 'show') {
            $spec['summary']   = 'Progress of the run';
            $spec['responses'] = [
                '200' => [
                    'description' => 'Current state',
                    'headers'     => ['Retry-After' => [
                        'schema'      => ['type' => 'integer'],
                        'description' => 'Only while the status is pending or processing',
                    ]],
                ] + $body,
                '404' => ['description' => 'Unknown id'],
            ];
        }

        if ($action === 'cancel') {
            $spec['summary']   = 'Cancel the run';
            $spec['responses'] = [
                '200' => ['description' => 'Cancelled'] + $body,
                '404' => ['description' => 'Unknown id'],
            ];
        }

        $operations = [];

        foreach ($route->methods() as $verb) {
            if (in_array($verb, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $operations[strtolower($verb)] = $this->secure(array_filter($spec), $route);
        }

        return $operations;
    }

    public function jobSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'State of a started run.',
            'required'    => ['id', 'key', 'status', 'total', 'processed', 'failed', 'progress', 'created_at'],
            'properties'  => [
                'id'          => ['type' => 'integer', 'description' => 'Identifier of the run', 'example' => 177],
                'key'         => ['type' => 'string', 'description' => 'Job type', 'example' => 'member-export'],
                'status'      => ['type' => 'string', 'enum' => JobStatus::values(), 'example' => 'processing'],
                'total'       => ['type' => 'integer', 'description' => 'Jobs in the run', 'example' => 7],
                'processed'   => ['type' => 'integer', 'description' => 'Of which succeeded', 'example' => 5],
                'failed'      => ['type' => 'integer', 'description' => 'Of which failed for good', 'example' => 0],
                'progress'    => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'example' => 71],
                'message'     => ['type' => ['string', 'null'], 'description' => 'Error text when the status is failed'],
                'result_url'  => [
                    'type'        => ['string', 'null'],
                    'format'      => 'uri',
                    'description' => 'Route to what the run produced, once there is something to fetch',
                ],
                'created_at'  => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-09-07T21:47:15.123456Z'],
                'started_at'  => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'finished_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ],
        ];
    }

    // ---------------------------------------------------- Hand written ones
    public function customOperation(
        Route $route,
        string $class,
        string $method,
        string $verb,
        ?ApiOperation $operation,
    ): array {
        $spec = [
            'tags'        => [$operation?->tag ?? class_basename($class)],
            'operationId' => $route->getName() ?: Str::camel(class_basename($class).'_'.$method),
            'summary'     => $operation?->summary ?? Str::headline($method),
            'description' => $operation?->description,
            'deprecated'  => $operation?->deprecated ?: null,
            'parameters'  => $this->pathParameters($route),
        ];

        $request = $operation?->request ?? $this->requestParameter($class, $method);

        if ($request && is_subclass_of($request, RestRequest::class)) {
            $instance = $this->instantiate($request);
            $rules    = $instance ? $this->safeRules($instance) : [];

            if (in_array($verb, ['POST', 'PUT', 'PATCH'], true) && $rules !== []) {
                $spec['requestBody'] = [
                    'required' => true,
                    'content'  => ['application/json' => ['schema' => $this->mapper->toSchema($rules)]],
                ];
            }

            if ($verb === 'GET' && $instance) {
                $spec['parameters'] = array_merge(
                    $spec['parameters'],
                    $this->filterParameters($instance->filterSet()),
                );
            }
        }

        $spec['responses'] = $operation?->responses ?? ['200' => ['description' => 'OK']];

        return array_filter($spec);
    }

    /** First FormRequest dependency in the method signature. */
    public function requestParameter(string $class, string $method): ?string
    {
        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type && ! $type->isBuiltin() && is_subclass_of($type->getName(), RestRequest::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    // ---------------------------------------------------------- Parameters
    public function pathParameters(Route $route): array
    {
        return array_map(fn (string $name) => [
            'name'     => $name,
            'in'       => 'path',
            'required' => true,
            'schema'   => ['type' => $name === 'id' ? 'integer' : 'string'],
        ], $route->parameterNames());
    }

    public function queryParameters(string $class): array
    {
        $request = $this->indexRequest($class);
        $keys    = $this->config['query'] ?? [];

        $parameters = $request ? $this->filterParameters($request->filterSet()) : [];

        $sortable = $request && $request->sortable() !== []
            ? ' Allowed: '.implode(', ', $request->sortable())
            : '';

        $parameters[] = [
            'name'        => $keys['sort'] ?? 'sort',
            'in'          => 'query',
            'description' => 'Comma separated. A leading minus reverses the direction: -created_at,name.'.$sortable,
            'schema'      => ['type' => 'string'],
        ];

        $parameters[] = [
            'name'   => $keys['page'] ?? 'page',
            'in'     => 'query',
            'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
        ];

        $parameters[] = [
            'name'   => $keys['per_page'] ?? 'per_page',
            'in'     => 'query',
            'schema' => [
                'type'    => 'integer',
                'minimum' => 1,
                'maximum' => $this->config['defaults']['max_per_page'] ?? 200,
                'default' => $this->config['defaults']['per_page'] ?? 25,
            ],
        ];

        if ($request && $request->relations() !== []) {
            $parameters[] = [
                'name'        => $keys['with'] ?? 'with',
                'in'          => 'query',
                'description' => 'Comma separated. Allowed: '.implode(', ', $request->relations()),
                'schema'      => ['type' => 'string'],
            ];
        }

        return $parameters;
    }

    /**
     * One parameter per field rather than one per operator.
     *
     * OpenAPI's deepObject style exists for exactly this: the parameter is
     * called filter[id], its properties are the allowed operators, and on
     * the wire it still reads ?filter[id][gt]=5. Nine rows in the user
     * interface collapse into one.
     */
    public function filterParameters(FilterSet $set): array
    {
        $key        = $this->config['query']['filter'] ?? 'filter';
        $parameters = [];

        foreach ($set->fields() as $field) {
            $type       = $set->type($field);
            $properties = [];
            $operators  = [];

            foreach ($set->operators($field) as $operator => $filter) {
                $schema = $filter->schema();

                // The type of the field wins, unless the operator brings
                // its own: in and notIn take lists, null takes a boolean.
                if (($schema['type'] ?? 'string') === 'string' && ! isset($schema['description'])) {
                    $schema['type'] = $type['type'];

                    if ($type['format']) {
                        $schema['format'] = $type['format'];
                    }
                }

                $schema['description'] = $filter->description();

                $properties[$operator] = $schema;
                $operators[]           = $operator;
            }

            $parameters[] = [
                'name'        => "{$key}[{$field}]",
                'in'          => 'query',
                'style'       => 'deepObject',
                'explode'     => true,
                'description' => 'Operators: '.implode(', ', $operators)
                    .". Example: {$key}[{$field}][".($operators[0] ?? 'eq').']=...',
                'schema'      => [
                    'type'                 => 'object',
                    'properties'           => $properties,
                    'additionalProperties' => false,
                ],
            ];
        }

        return $parameters;
    }

    // ------------------------------------------------------------- Schemas
    public function schemaFor(string $class, RestResource $resource): array
    {
        $name = class_basename($resource->model);

        if (isset($this->schemas[$name])) {
            return $this->schemas[$name];
        }

        $rules  = $this->rulesFor($class, 'update') ?: $this->rulesFor($class, 'store');
        $schema = $this->mapper->toSchema($rules);

        unset($schema['required']);

        $schema['properties'] = $this->properties($resource->model, $schema['properties'] ?? []);

        foreach ($this->annotations($class) as $annotation) {
            if (! isset($schema['properties'][$annotation->field])) {
                continue;
            }

            $schema['properties'][$annotation->field] = array_filter(array_merge(
                $schema['properties'][$annotation->field],
                [
                    'description' => $annotation->description,
                    'example'     => $annotation->example,
                    'format'      => $annotation->format,
                ],
            ), fn ($value) => $value !== null);
        }

        return $this->schemas[$name] = $schema;
    }

    /**
     * Fields of a resource: the table columns as the base, overridden by
     * whatever the validation rules say - those know more.
     */
    public function properties(string $model, array $fromRules): array
    {
        $columns = ($this->config['openapi']['schema_from_model'] ?? true)
            ? $this->models->properties($model)
            : [];

        if ($columns === []) {
            // Without a database this is all that can be stated safely.
            $columns = [
                'id'         => ['type' => 'integer', 'readOnly' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time', 'readOnly' => true],
                'updated_at' => ['type' => 'string', 'format' => 'date-time', 'readOnly' => true],
            ];
        }

        foreach ($fromRules as $field => $property) {
            $columns[$field] = array_merge($columns[$field] ?? [], $property);
        }

        return $columns;
    }

    /**
     * What the caller must not set does not belong in the request body
     * either: the primary key and the timestamps.
     */
    public function writable(array $schema, string $model): array
    {
        foreach ($this->models->readOnly($model) as $field) {
            unset($schema['properties'][$field]);

            if (isset($schema['required'])) {
                $schema['required'] = array_values(array_diff($schema['required'], [$field]));

                if ($schema['required'] === []) {
                    unset($schema['required']);
                }
            }
        }

        return $schema;
    }

    /** @return array<int, ApiSchema> */
    public function annotations(string $class): array
    {
        return array_map(
            fn ($attribute) => $attribute->newInstance(),
            (new ReflectionClass($class))->getAttributes(ApiSchema::class),
        );
    }

    // ------------------------------------------------------------ Requests
    public function rulesFor(string $controller, string $action): array
    {
        $instance = $this->controller($controller);
        $class    = $instance?->requestFor($action);

        if (! $class) {
            return [];
        }

        $request = $this->instantiate($class);

        return $request ? $this->safeRules($request) : [];
    }

    public function indexRequest(string $controller): ?RestRequest
    {
        $class = $this->controller($controller)?->requestFor('index');

        return $class ? $this->instantiate($class) : null;
    }

    public function controller(string $class): ?object
    {
        try {
            return new $class();
        } catch (Throwable) {
            return null;
        }
    }

    public function instantiate(string $class): ?RestRequest
    {
        try {
            $request = new $class();

            return $request instanceof RestRequest ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** rules() may reach into the request, which does not exist while generating. */
    public function safeRules(RestRequest $request): array
    {
        try {
            return $request->rules();
        } catch (Throwable) {
            return [];
        }
    }
}
