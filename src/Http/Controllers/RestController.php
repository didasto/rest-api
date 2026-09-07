<?php

namespace Didasto\RestApi\Http\Controllers;

use Didasto\RestApi\Attributes\RestResource;
use Didasto\RestApi\Http\Requests\ListRequest;
use Didasto\RestApi\Http\Requests\RestRequest;
use Didasto\RestApi\Query\QueryBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ReflectionClass;
use RuntimeException;

/**
 * Basis fuer model-basierte Ressourcen.
 *
 * Alles ist public oder protected und ohne final - jede Stufe laesst sich
 * einzeln ersetzen, ohne die uebrigen anzufassen.
 *
 *   #[RestResource(model: Mitglied::class, except: ['delete'])]
 *   class MitgliedController extends RestController
 *   {
 *       protected ?string $listRequest   = MitgliedListRequest::class;
 *       protected ?string $storeRequest  = MitgliedStoreRequest::class;
 *       protected ?string $updateRequest = MitgliedStoreRequest::class;
 *   }
 */
abstract class RestController
{
    protected ?string $model = null;

    protected ?string $listRequest = null;

    protected ?string $storeRequest = null;

    protected ?string $updateRequest = null;

    /** Spalte, ueber die geladen wird. null = Primaerschluessel. */
    protected ?string $key = null;

    // ------------------------------------------------------------- Aktionen
    public function list(Request $request): JsonResponse
    {
        $form = $this->formRequest('list');

        $builder = new QueryBuilder(
            request: $request,
            filters: $form->filterSet(),
            sortable: $form->sortable(),
            relations: $form->relations(),
            config: $this->config(),
        );

        return $this->collection($builder->paginate($builder->apply($this->query())));
    }

    public function show(Request $request, string|int $id): JsonResponse
    {
        return $this->item($this->find($id));
    }

    public function store(Request $request): JsonResponse
    {
        $form  = $this->formRequest('store');
        $model = $this->newModel();

        $model->fill($this->prepare($form->validated(), $model));
        $this->persist($model);

        return $this->item($model, 201);
    }

    public function update(Request $request, string|int $id): JsonResponse
    {
        $form  = $this->formRequest('update');
        $model = $this->find($id);

        $model->fill($this->prepare($form->validated(), $model));
        $this->persist($model);

        return $this->item($model);
    }

    public function delete(Request $request, string|int $id): JsonResponse
    {
        $this->find($id)->delete();

        return new JsonResponse(null, 204);
    }

    // ------------------------------------------------------------- Model
    public function modelClass(): string
    {
        if ($this->model) {
            return $this->model;
        }

        $attribute = $this->attribute();

        if (! $attribute) {
            throw new RuntimeException(static::class.': weder $model gesetzt noch #[RestResource] vorhanden.');
        }

        return $attribute->model;
    }

    public function attribute(): ?RestResource
    {
        $attributes = (new ReflectionClass(static::class))->getAttributes(RestResource::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    public function newModel(): Model
    {
        $class = $this->modelClass();

        return new $class();
    }

    public function query(): Builder
    {
        return $this->modelClass()::query();
    }

    public function keyName(): string
    {
        return $this->key
            ?? $this->attribute()?->key
            ?? $this->config()['defaults']['key']
            ?? $this->newModel()->getKeyName();
    }

    public function find(string|int $id): Model
    {
        return $this->query()->where($this->keyName(), $id)->firstOrFail();
    }

    /** Letzte Station vor dem Speichern - hier lassen sich Werte ergaenzen. */
    public function prepare(array $data, Model $model): array
    {
        return $data;
    }

    public function persist(Model $model): Model
    {
        $model->save();

        return $model;
    }

    // ------------------------------------------------------------- Requests
    /** @return array<string, ?string> */
    public function requestMap(): array
    {
        return [
            'list'   => $this->listRequest,
            'store'  => $this->storeRequest,
            'update' => $this->updateRequest,
        ];
    }

    public function requestClass(string $action): ?string
    {
        return $this->requestMap()[$action] ?? null;
    }

    /**
     * Aufloesen ueber den Container - dabei validiert Laravel den Request
     * selbst und wirft bei Fehlern die uebliche 422-Antwort.
     */
    public function formRequest(string $action): RestRequest
    {
        $class = $this->requestClass($action);

        if (! $class) {
            if ($action === 'list') {
                return app(ListRequest::class);
            }

            throw new RuntimeException(
                static::class.": fuer '{$action}' ist keine Request-Klasse gesetzt (\$".$action.'Request).'
            );
        }

        return app($class);
    }

    // ------------------------------------------------------------- Ausgabe
    /** Ein Model in das Antwort-Array uebersetzen. */
    public function transform(Model $model): array
    {
        return $model->toArray();
    }

    public function item(Model $model, int $status = 200): JsonResponse
    {
        return new JsonResponse($this->transform($model), $status);
    }

    public function collection(LengthAwarePaginator $paginator): JsonResponse
    {
        $items = array_map(
            fn (Model $model) => $this->transform($model),
            $paginator->items(),
        );

        return new JsonResponse($items, 200, $this->paginationHeaders($paginator));
    }

    /** Flache Antworten - die Paginierung steht deshalb in den Headern. */
    public function paginationHeaders(LengthAwarePaginator $paginator): array
    {
        $links = array_filter([
            $paginator->previousPageUrl() ? '<'.$paginator->previousPageUrl().'>; rel="prev"' : null,
            $paginator->nextPageUrl() ? '<'.$paginator->nextPageUrl().'>; rel="next"' : null,
            '<'.$paginator->url(1).'>; rel="first"',
            '<'.$paginator->url($paginator->lastPage()).'>; rel="last"',
        ]);

        return [
            'X-Total-Count' => (string) $paginator->total(),
            'X-Page'        => (string) $paginator->currentPage(),
            'X-Per-Page'    => (string) $paginator->perPage(),
            'X-Last-Page'   => (string) $paginator->lastPage(),
            'Link'          => implode(', ', $links),
        ];
    }

    public function config(): array
    {
        return config('rest-api', []);
    }
}
