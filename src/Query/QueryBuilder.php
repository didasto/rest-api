<?php

namespace Didasto\RestApi\Query;

use Didasto\RestApi\Exceptions\InvalidQueryException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Applies filtering, sorting, eager loading and pagination to an Eloquent
 * query, driven by the query string of the request.
 */
class QueryBuilder
{
    public function __construct(
        public Request $request,
        public FilterSet $filters,
        public array $sortable = [],
        public array $relations = [],
        public array $config = [],
    ) {}

    public function key(string $name): string
    {
        return $this->config['query'][$name] ?? $name;
    }

    public function apply(Builder $query): Builder
    {
        $query = $this->applyFilters($query);
        $query = $this->applyRelations($query);

        return $this->applySort($query);
    }

    public function applyFilters(Builder $query): Builder
    {
        $input = $this->request->input($this->key('filter'), []);

        if (! is_array($input)) {
            throw InvalidQueryException::malformedFilter();
        }

        foreach ($input as $field => $operators) {
            // The short form ?filter[name]=Smith means equality.
            $pairs = is_array($operators) ? $operators : ['eq' => $operators];

            foreach ($pairs as $operator => $value) {
                $filter = $this->filters->get((string) $field, (string) $operator);

                if (! $filter) {
                    throw InvalidQueryException::unknownFilter(
                        (string) $field,
                        (string) $operator,
                        $this->filters,
                    );
                }

                $query = $filter->apply($query, $filter->parse($value));
            }
        }

        return $query;
    }

    public function applySort(Builder $query): Builder
    {
        $sort = (string) $this->request->input($this->key('sort'), '');

        if ($sort === '') {
            return $query;
        }

        foreach (array_filter(array_map('trim', explode(',', $sort))) as $column) {
            $direction = str_starts_with($column, '-') ? 'desc' : 'asc';
            $column    = ltrim($column, '-+');

            if ($this->sortable !== [] && ! in_array($column, $this->sortable, true)) {
                throw InvalidQueryException::unknownSort($column, $this->sortable);
            }

            $query = $query->orderBy($column, $direction);
        }

        return $query;
    }

    public function applyRelations(Builder $query): Builder
    {
        $with = $this->request->input($this->key('with'), '');
        $with = is_array($with)
            ? $with
            : array_filter(array_map('trim', explode(',', (string) $with)));

        if ($with === []) {
            return $query;
        }

        foreach ($with as $relation) {
            if ($this->relations !== [] && ! in_array($relation, $this->relations, true)) {
                throw InvalidQueryException::unknownRelation($relation, $this->relations);
            }
        }

        return $query->with($with);
    }

    public function perPage(): int
    {
        $default = (int) ($this->config['defaults']['per_page'] ?? 25);
        $max     = (int) ($this->config['defaults']['max_per_page'] ?? 200);
        $wanted  = (int) $this->request->input($this->key('per_page'), $default);

        return max(1, min($wanted ?: $default, $max));
    }

    public function paginate(Builder $query): LengthAwarePaginator
    {
        return $query->paginate(
            perPage: $this->perPage(),
            pageName: $this->key('page'),
            page: (int) $this->request->input($this->key('page'), 1),
        );
    }
}
