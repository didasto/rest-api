<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Controller-Verzeichnisse
    |--------------------------------------------------------------------------
    |
    | Hier wird nach Klassen mit #[RestResource] gesucht. Handgeschriebene
    | Endpunkte mit den Spatie-Attributen registriert Spatie selbst - dafuer
    | muss nichts doppelt eingetragen werden.
    |
    | Je Verzeichnis: prefix, middleware, patterns.
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
    | Standardverhalten der Model-Ressourcen
    |--------------------------------------------------------------------------
    |
    | actions       Reihenfolge und Umfang, wenn weder only noch except gesetzt.
    | parameter     Name des Routenparameters: /mitglieder/{id}
    | key           Spalte, ueber die geladen wird. null = Primaerschluessel.
    | per_page      Standard-Seitengroesse, max_per_page deckelt ?per_page.
    */

    'defaults' => [
        'actions'      => ['list', 'show', 'store', 'update', 'delete'],
        'parameter'    => 'id',
        'key'          => null,
        'per_page'     => 25,
        'max_per_page' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Query-Parameter
    |--------------------------------------------------------------------------
    |
    | Filter werden verschachtelt uebergeben: ?filter[id][gte]=5
    */

    'query' => [
        'filter'   => 'filter',
        'sort'     => 'sort',
        'page'     => 'page',
        'per_page' => 'per_page',
        'fields'   => 'fields',
        'with'     => 'with',
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAPI
    |--------------------------------------------------------------------------
    |
    | route      Wo das Dokument ausgeliefert wird. null = abgeschaltet.
    | include    Nur Routen, deren URI auf eines dieser Muster passt.
    | cache      Im Produktivbetrieb einmal bauen und behalten.
    */

    'openapi' => [
        'route'      => 'api/doc',
        'middleware' => ['api'],
        'include'    => ['api/*'],
        'exclude'    => ['api/doc'],
        'cache'      => env('REST_API_DOC_CACHE', false),

        /*
        | Felder der Antwort-Schemas aus den Tabellenspalten ableiten, wenn
        | (noch) keine Store- oder Update-Request existiert. Die Regeln der
        | Request-Klassen bleiben die genauere Quelle und ueberschreiben.
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
        | Der "Authorize"-Knopf in Swagger/Scalar entsteht aus schemes.
        | middleware ordnet zu, welche Route welches Schema braucht -
        | gepflegt wird also nur die Middleware an der Route selbst.
        |
        | scopes: Middleware mit Parametern (role:kassierer) landet als
        | Scope im Dokument, wenn das Schema Scopes kennt.
        */
        'security' => [
            'schemes' => [
                'bearerAuth' => [
                    'type'         => 'http',
                    'scheme'       => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description'  => 'Access Token aus Keycloak.',
                ],
            ],

            'middleware' => [
                'auth'      => 'bearerAuth',
                'auth.api'  => 'bearerAuth',
                'role'      => 'bearerAuth',
                'can'       => 'bearerAuth',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job-APIs
    |--------------------------------------------------------------------------
    |
    | POST legt einen Lauf an und gibt dessen id zurueck, GET liefert den
    | Stand. Die Fortschrittszahlen kommen live aus Laravels Batch - die
    | Jobs selbst muessen nichts melden.
    |
    | table       Name der Tabelle fuer die Laeufe.
    | retry_after Sekunden fuer den Retry-After-Header, solange es laeuft.
    | prune       Laeufe aelter als X Tage entfernt rest-api:prune-jobs.
    */

    'jobs' => [
        'table'       => 'api_job_runs',
        'retry_after' => 2,
        'prune'       => 30,
        'queue'       => env('REST_API_JOB_QUEUE'),
    ],
];
