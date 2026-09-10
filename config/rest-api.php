<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Controller directories
    |--------------------------------------------------------------------------
    |
    | Scanned for classes carrying #[RestResource] or #[RestJob]. Hand
    | written endpoints using the Spatie route attributes are registered by
    | Spatie itself and need no entry here.
    |
    | Per directory: prefix, middleware, patterns.
    */

    'directories' => [
        app_path('Http/Controllers/Api') => [
            'prefix'     => 'api',
            'middleware' => ['api'],
            'patterns'   => ['*Controller.php'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults for model resources
    |--------------------------------------------------------------------------
    |
    | actions       Which actions exist when neither only nor except is set.
    | parameter     Name of the route parameter: /members/{id}
    | key           Column a record is looked up by. Null = primary key.
    | per_page      Default page size; max_per_page caps ?per_page.
    */

    'defaults' => [
        'actions'      => ['index', 'show', 'store', 'update', 'destroy'],
        'parameter'    => 'id',
        'key'          => null,
        'per_page'     => 25,
        'max_per_page' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Query parameters
    |--------------------------------------------------------------------------
    |
    | Filters are passed nested: ?filter[id][gte]=5
    */

    'query' => [
        'filter'   => 'filter',
        'sort'     => 'sort',
        'page'     => 'page',
        'per_page' => 'per_page',
        'with'     => 'with',
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAPI
    |--------------------------------------------------------------------------
    |
    | route      Where the document is served. Null disables it.
    | include    Only routes whose URI matches one of these patterns.
    | cache      Build once and keep it in production.
    */

    'openapi' => [
        'route'      => 'api/doc',
        'middleware' => ['api'],
        'include'    => ['api/*'],
        'exclude'    => ['api/doc'],
        'cache'      => env('REST_API_DOC_CACHE', false),

        /*
        | Derive the fields of the response schemas from the table columns
        | when there is no store or update request yet. The rules of the
        | request classes stay the more precise source and override them.
        */
        'schema_from_model' => true,

        'info' => [
            'title'       => env('APP_NAME', 'API').' API',
            'version'     => '1.0.0',
            'description' => '',
        ],

        'servers' => [
            ['url' => env('APP_URL', 'http://localhost')],
        ],

        /*
        | The Authorize button in Swagger or Scalar comes from schemes.
        | middleware says which route needs which scheme, so the route
        | stays the only place this is maintained.
        |
        | scopes_from lists the middleware whose parameter is a permission
        | and therefore becomes a scope. auth:api is deliberately not in
        | that list - there the parameter names a guard.
        */
        'security' => [
            'schemes' => [
                'bearerAuth' => [
                    'type'         => 'http',
                    'scheme'       => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description'  => 'Access token issued by the identity provider.',
                ],
            ],

            'middleware' => [
                'auth'     => 'bearerAuth',
                'auth.api' => 'bearerAuth',
                'role'     => 'bearerAuth',
                'can'      => 'bearerAuth',
            ],

            'scopes_from' => ['role', 'can'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job APIs
    |--------------------------------------------------------------------------
    |
    | POST creates a run and returns its id, GET reports the progress. The
    | numbers are read live from Laravel's batch, so the jobs themselves
    | report nothing.
    |
    | table       Table holding the runs.
    | retry_after Seconds for the Retry-After header while a run is open.
    | prune       rest-api:prune-jobs removes runs older than this many days.
    */

    'jobs' => [
        'table'       => 'api_job_runs',
        'retry_after' => 2,
        'prune'       => 30,
        'queue'       => env('REST_API_JOB_QUEUE'),
    ],
];
