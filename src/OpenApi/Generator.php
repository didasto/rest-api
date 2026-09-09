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
use Throwable;

/**
 * Baut das OpenAPI-Dokument aus den registrierten Routen.
 *
 * Quellen: #[RestResource] fuer die Model-Ressourcen, #[ApiOperation] fuer
 * handgeschriebene Endpunkte, und in beiden Faellen die Regeln und Filter
 * der Request-Klassen.
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
                'securitySchemes' => $this->config['openapi']['security']['schemes']
                    ?? $this->config['openapi']['security_schemes']
                    ?? null,
            ]),
        ]));
    }

    /**
     * Ein leeres properties muss im JSON {} sein, nicht [] - sonst ist das
     * Dokument nach OpenAPI ungueltig und Generatoren steigen aus.
     */
    public function asObjects(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if (in_array($key, ['properties', 'schemas', 'headers', 'responses'], true) && $value === []) {
                $node[$key] = new \stdClass();

                continue;
            }

            $node[$key] = $this->asObjects($value);
        }

        return $node;
    }

    // ------------------------------------------------------------- Routen
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

    /** @return array<string, array> HTTP-Methode (klein) => Operation */
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

        $resource = $this->restResource($class);

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

    // ------------------------------------------------------------ Sicherheit
    /**
     * Welche Middleware ein Token verlangt, steht in der Config. Aus
     * role:kassierer wird der Scope - die Route bleibt die einzige Quelle.
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

            $scopes = $arguments === null ? [] : array_map('trim', explode(',', $arguments));

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
            '401' => ['description' => 'Nicht angemeldet oder Token ungueltig'],
            '403' => ['description' => 'Angemeldet, aber ohne die noetige Berechtigung'],
        ];

        return $spec;
    }

    // ------------------------------------------------------------------ Jobs
    /** @return array<string, array> */
    public function jobOperations(Route $route, string $class, string $action, RestJob $job, ?ApiOperation $operation): array
    {
        $tag  = $operation?->tag ?? $job->tag ?? Str::headline($job->key);
        $this->schemas['JobRun'] ??= $this->jobSchema();

        $body = ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/JobRun']]]];

        $spec = [
            'tags'        => [$tag],
            'operationId' => $route->getName() ?: Str::camel($job->key.'_'.$action),
            'parameters'  => $this->pathParameters($route),
        ];

        if ($action === 'store') {
            $rules = $this->rulesFor($class, 'store');

            $spec['summary']     = $operation?->summary ?? $job->summary ?? Str::headline($job->key).' anstossen';
            $spec['description'] = $operation?->description
                ?? 'Legt einen Lauf an und gibt dessen id zurueck. Der Stand wird danach ueber GET auf dieselbe Route mit angehaengter id abgefragt; Retry-After nennt ein sinnvolles Abfrageintervall.';

            if ($rules !== []) {
                $spec['requestBody'] = [
                    'required' => true,
                    'content'  => ['application/json' => ['schema' => $this->writable(
                        $this->mapper->toSchema($rules),
                        $resource->model,
                    )]],
                ];
            }

            $spec['responses'] = [
                '202' => [
                    'description' => 'Angenommen, Lauf angelegt',
                    'headers'     => [
                        'Location'    => ['schema' => ['type' => 'string'], 'description' => 'Route fuer den Stand'],
                        'Retry-After' => ['schema' => ['type' => 'integer'], 'description' => 'Sekunden bis zur naechsten Abfrage'],
                    ],
                ] + $body,
                '422' => $this->validationError(),
            ];
        }

        if ($action === 'show') {
            $spec['summary']   = 'Stand des Laufs';
            $spec['responses'] = [
                '200' => [
                    'description' => 'Aktueller Stand',
                    'headers'     => ['Retry-After' => ['schema' => ['type' => 'integer'], 'description' => 'Nur solange status pending oder processing ist']],
                ] + $body,
                '404' => ['description' => 'Unbekannte id'],
            ];
        }

        if ($action === 'cancel') {
            $spec['summary']   = 'Lauf abbrechen';
            $spec['responses'] = ['200' => ['description' => 'Abgebrochen'] + $body, '404' => ['description' => 'Unbekannte id']];
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
            'description' => 'Stand eines angestossenen Laufs.',
            'required'    => ['id', 'key', 'status', 'total', 'processed', 'failed', 'progress', 'created_at'],
            'properties'  => [
                'id'          => ['type' => 'integer', 'description' => 'Kennung des Laufs', 'example' => 177],
                'key'         => ['type' => 'string', 'description' => 'Job-Typ', 'example' => 'mitglieder-export'],
                'status'      => ['type' => 'string', 'enum' => JobStatus::values(), 'example' => 'processing'],
                'total'       => ['type' => 'integer', 'description' => 'Aufgaben im Lauf', 'example' => 7],
                'processed'   => ['type' => 'integer', 'description' => 'Davon abgearbeitet', 'example' => 5],
                'failed'      => ['type' => 'integer', 'description' => 'Davon fehlgeschlagen', 'example' => 0],
                'progress'    => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'example' => 71],
                'message'     => ['type' => ['string', 'null'], 'description' => 'Fehlertext bei status failed'],
                'result_url'  => ['type' => ['string', 'null'], 'format' => 'uri', 'description' => 'Route zur erzeugten Ressource, sobald vorhanden'],
                'created_at'  => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-09-07T21:47:15.123456Z'],
                'started_at'  => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'finished_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ],
        ];
    }

    public function apiOperation(string $class, string $method): ?ApiOperation
    {
        $attributes = (new ReflectionMethod($class, $method))->getAttributes(ApiOperation::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    // -------------------------------------------------------- Ressourcen
    public function resourceOperation(
        Route $route,
        string $class,
        string $action,
        string $verb,
        RestResource $resource,
        ?ApiOperation $operation,
    ): array {
        $name   = class_basename($resource->model);
        $tag    = $operation?->tag ?? $resource->tag ?? $name;
        $schema = $this->schemaFor($class, $resource);

        $spec = [
            'tags'        => [$tag],
            'operationId' => $route->getName() ?: Str::camel("{$name}_{$action}"),
            'summary'     => $operation?->summary ?? $this->summary($action, $name, $verb),
            'parameters'  => $this->pathParameters($route),
        ];

        if ($operation?->description) {
            $spec['description'] = $operation->description;
        }

        if ($action === 'index') {
            $spec['parameters'] = array_merge($spec['parameters'], $this->queryParameters($class));
            $spec['responses'] = [
                '200' => [
                    'description' => 'Liste. Paginierung in den Headern X-Total-Count, X-Page, X-Per-Page, X-Last-Page und Link.',
                    'headers'     => $this->paginationHeaders(),
                    'content'     => ['application/json' => ['schema' => [
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

            // Ohne Request-Klasse gibt es nichts zu beschreiben - ein leerer
            // Body in der Doku waere schlechter als gar keiner.
            if ($rules !== []) {
                $spec['requestBody'] = [
                    'required' => true,
                    'content'  => ['application/json' => ['schema' => $this->writable(
                        $this->mapper->toSchema($rules),
                        $resource->model,
                    )]],
                ];
            }

            // PUT und PATCH nehmen dieselben Regeln entgegen. Eine
            // operationId darf im Dokument nur einmal vorkommen.
            if ($verb === 'PATCH') {
                $spec['operationId'] = ($spec['operationId'] ?? '').'Patch';
            }
        }

        $spec['responses'] = $this->responses($action, $name, $verb);

        return array_filter($spec);
    }

    public function summary(string $action, string $name, string $verb): string
    {
        return match ($action) {
            'index'  => "{$name} auflisten",
            'show'   => "{$name} anzeigen",
            'store'  => "{$name} anlegen",
            'update' => "{$name} aktualisieren",
            'destroy' => "{$name} loeschen",
            default  => Str::headline($action),
        };
    }

    public function responses(string $action, string $name, string $verb): array
    {
        $body = ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$name}"]]]];

        return match ($action) {
            'store'  => ['201' => ['description' => 'Angelegt'] + $body, '422' => $this->validationError()],
            'update' => ['200' => ['description' => 'Aktualisiert'] + $body, '404' => ['description' => 'Nicht gefunden'], '422' => $this->validationError()],
            'destroy' => ['204' => ['description' => 'Geloescht'], '404' => ['description' => 'Nicht gefunden']],
            default  => ['200' => ['description' => 'OK'] + $body, '404' => ['description' => 'Nicht gefunden']],
        };
    }

    /** Unbekannter Filter, unbekannter Operator, gesperrte Sortierspalte. */
    public function queryError(): array
    {
        return [
            'description' => 'Ungueltige Query - unbekannter Filter, Operator oder Sortierspalte',
            'content'     => ['application/json' => ['schema' => [
                'type'       => 'object',
                'properties' => ['message' => ['type' => 'string']],
            ]]],
        ];
    }

    public function validationError(): array
    {
        return [
            'description' => 'Validierung fehlgeschlagen',
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
            'X-Total-Count' => ['schema' => ['type' => 'integer'], 'description' => 'Treffer insgesamt'],
            'X-Page'        => ['schema' => ['type' => 'integer'], 'description' => 'Aktuelle Seite'],
            'X-Per-Page'    => ['schema' => ['type' => 'integer'], 'description' => 'Eintraege pro Seite'],
            'X-Last-Page'   => ['schema' => ['type' => 'integer'], 'description' => 'Letzte Seite'],
            'Link'          => ['schema' => ['type' => 'string'], 'description' => 'prev, next, first, last'],
        ];
    }

    // ------------------------------------------------- Handgeschriebenes
    public function customOperation(Route $route, string $class, string $method, string $verb, ?ApiOperation $operation): array
    {
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
                $spec['parameters'] = array_merge($spec['parameters'], $this->filterParameters($instance->filterSet()));
            }
        }

        $spec['responses'] = $operation?->responses ?? ['200' => ['description' => 'OK']];

        return array_filter($spec);
    }

    /** Erste FormRequest-Abhaengigkeit in der Signatur. */
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

    // ------------------------------------------------------- Parameter
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

        $parameters[] = [
            'name'        => $keys['sort'] ?? 'sort',
            'in'          => 'query',
            'description' => 'Kommagetrennt, Minus kehrt die Richtung um: -erstellt_am,name'
                .($request && $request->sortable() !== [] ? ' Erlaubt: '.implode(', ', $request->sortable()) : ''),
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
                'description' => 'Kommagetrennt. Erlaubt: '.implode(', ', $request->relations()),
                'schema'      => ['type' => 'string'],
            ];
        }

        return $parameters;
    }

    /**
     * Ein Parameter je Feld statt einer je Operator.
     *
     * style deepObject ist in OpenAPI genau dafuer gedacht: der Parameter
     * heisst filter[id], seine Felder sind die erlaubten Operatoren, und
     * auf der Leitung steht weiterhin ?filter[id][gt]=5. Neun Zeilen in
     * der Oberflaeche werden so zu einer.
     */
    public function filterParameters(FilterSet $set): array
    {
        $key        = $this->config['query']['filter'] ?? 'filter';
        $parameters = [];

        foreach ($set->fields() as $field) {
            $type       = $set->type($field);
            $properties = [];
            $namen      = [];

            foreach ($set->operators($field) as $operator => $filter) {
                $schema = $filter->schema();

                // Der Typ des Feldes gilt, ausser der Operator bringt einen
                // eigenen mit (in und notIn nehmen Listen, null ein Boolean).
                if (($schema['type'] ?? 'string') === 'string' && ! isset($schema['description'])) {
                    $schema['type'] = $type['type'];

                    if ($type['format']) {
                        $schema['format'] = $type['format'];
                    }
                }

                $schema['description'] = $filter->description();

                $properties[$operator] = $schema;
                $namen[] = $operator;
            }

            $parameters[] = [
                'name'        => "{$key}[{$field}]",
                'in'          => 'query',
                'style'       => 'deepObject',
                'explode'     => true,
                'description' => 'Operatoren: '.implode(', ', $namen)
                    .". Beispiel: {$key}[{$field}][".($namen[0] ?? 'eq').']=...',
                'schema'      => [
                    'type'                 => 'object',
                    'properties'           => $properties,
                    'additionalProperties' => false,
                ],
            ];
        }

        return $parameters;
    }

    // --------------------------------------------------------- Schemas
    public function schemaFor(string $class, RestResource $resource): array
    {
        $name = class_basename($resource->model);

        if (isset($this->schemas[$name])) {
            return $this->schemas[$name];
        }

        $rules  = $this->rulesFor($class, 'update') ?: $this->rulesFor($class, 'store');
        $schema = $this->mapper->toSchema($rules);

        unset($schema['required']);

        // Reihenfolge: Tabellenspalten als Basis, darueber die Regeln der
        // Request-Klassen - die wissen mehr (maxLength, enum, format).
        $schema['properties'] = $this->properties(
            $resource->model,
            $schema['properties'] ?? [],
        );

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
     * Was der Client nicht setzen darf, gehoert auch nicht in den
     * Request-Body: der Primaerschluessel und die Zeitstempel.
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

    /**
     * Felder der Ressource: Spalten der Tabelle, ueberschrieben von dem,
     * was die Request-Regeln hergeben.
     */
    public function properties(string $model, array $fromRules): array
    {
        $columns = ($this->config['openapi']['schema_from_model'] ?? true)
            ? $this->models->properties($model)
            : [];

        if ($columns === []) {
            // Ohne Datenbank bleibt es beim bisherigen Verhalten.
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

    /** @return array<int, ApiSchema> */
    public function annotations(string $class): array
    {
        return array_map(
            fn ($attribute) => $attribute->newInstance(),
            (new ReflectionClass($class))->getAttributes(ApiSchema::class),
        );
    }

    // -------------------------------------------------------- Requests
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

    /** rules() darf auf den Request zugreifen - beim Generieren gibt es den nicht. */
    public function safeRules(RestRequest $request): array
    {
        try {
            return $request->rules();
        } catch (Throwable) {
            return [];
        }
    }

}
