<?php

namespace Didasto\RestApi\Http\Controllers;

use Didasto\RestApi\Attributes\RestResource;
use Didasto\RestApi\Http\Requests\IndexRequest;
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
 * Base class for model backed resources.
 *
 * Every method is public or protected and no class is final, so each step
 * can be replaced on its own without touching the others. The methods
 * below are the intended extension points:
 *
 *   requestFor()          which request class belongs to which action
 *   query()               the starting point of every read
 *   find()                how a single record is looked up
 *   prepare()             last stop before writing, to add or drop values
 *   persist()             how a record is written
 *   transform()           how a record turns into the response payload
 *   item(), collection()  how a response is assembled
 *   paginationHeaders()   which headers describe a page
 *
 * Example:
 *
 *     #[RestResource(model: Member::class, except: ['destroy'])]
 *     class MemberController extends RestController
 *     {
 *         public function requestFor(string $action): ?string
 *         {
 *             return match ($action) {
 *                 'index'  => MemberIndexRequest::class,
 *                 'store'  => MemberStoreRequest::class,
 *                 'update' => MemberUpdateRequest::class,
 *                 default  => null,
 *             };
 *         }
 *     }
 */
abstract class RestController
{
    protected ?string $model = null;

    /** Column a record is looked up by. Null means the primary key. */
    protected ?string $key = null;

    // ------------------------------------------------------------- Actions
    public function index(Request $request): JsonResponse
    {
        $form = $this->formRequest('index');

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
        // Resolving the request runs authorize(), if one is configured.
        $this->formRequest('show');

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

    public function destroy(Request $request, string|int $id): JsonResponse
    {
        $this->formRequest('destroy');

        $this->find($id)->delete();

        return new JsonResponse(null, 204);
    }

    // --------------------------------------------------------------- Model
    public function modelClass(): string
    {
        if ($this->model) {
            return $this->model;
        }

        $attribute = $this->attribute();

        if (! $attribute) {
            throw new RuntimeException(
                static::class.': neither $model is set nor is #[RestResource] present.'
            );
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

    /** Last stop before writing - a good place to add or drop values. */
    public function prepare(array $data, Model $model): array
    {
        return $data;
    }

    public function persist(Model $model): Model
    {
        $model->save();

        return $model;
    }

    // ------------------------------------------------------------ Requests
    /**
     * Which request class belongs to which action. The single place that
     * knows this, so it can also branch by role, tenant or API version.
     *
     *     public function requestFor(string $action): ?string
     *     {
     *         return match ($action) {
     *             'index'   => MemberIndexRequest::class,
     *             'show'    => MemberShowRequest::class,
     *             'store'   => MemberStoreRequest::class,
     *             'update'  => MemberUpdateRequest::class,
     *             'destroy' => MemberDestroyRequest::class,
     *             default   => null,
     *         };
     *     }
     *
     * Null means no request class. For index the package falls back to
     * IndexRequest, show and destroy run without one, store and update
     * require one.
     */
    public function requestFor(string $action): ?string
    {
        return null;
    }

    /**
     * Resolving through the container is what triggers Laravel's own
     * validation, including the usual 422 response on failure.
     */
    public function formRequest(string $action): ?RestRequest
    {
        $class = $this->requestFor($action);

        if (! $class) {
            return match ($action) {
                'index'           => app(IndexRequest::class),
                'show', 'destroy' => null,
                default           => throw new RuntimeException(
                    static::class.": requestFor('{$action}') returned no request class."
                ),
            };
        }

        return app($class);
    }

    // -------------------------------------------------------------- Output
    /** Turn one record into the response payload. */
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

    /**
     * Responses are flat, so the pagination goes into headers rather than
     * wrapping the payload in an envelope.
     */
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
