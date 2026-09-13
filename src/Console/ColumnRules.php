<?php

namespace Didasto\RestApi\Console;

use Didasto\RestApi\OpenApi\ModelSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns table columns into a first draft of validation rules and filters.
 *
 * A draft, not the truth: what a field really has to satisfy is a
 * question the database cannot answer. Nothing is guessed when the table
 * is missing - an empty result is honest, half true rules are not.
 */
class ColumnRules
{
    public function __construct(public ModelSchema $schema = new ModelSchema()) {}

    /** @return array<int, array> the raw column descriptions of the table */
    public function columns(string $modelClass): array
    {
        $model = $this->schema->model($modelClass);

        return $model instanceof Model ? $this->schema->columns($model) : [];
    }

    /** Columns the caller must not send: the key and the timestamps. */
    public function skipped(string $modelClass): array
    {
        $model = $this->schema->model($modelClass);

        return $model instanceof Model
            ? $this->schema->readOnlyFields($model)
            : ['id', 'created_at', 'updated_at'];
    }

    /**
     * @return array<string, string> column => rule string
     */
    public function rules(string $modelClass, bool $partial = false): array
    {
        $model = $this->schema->model($modelClass);
        $skip  = array_merge(
            $this->skipped($modelClass),
            $model instanceof Model ? $model->getHidden() : [],
        );
        $rules = [];

        foreach ($this->columns($modelClass) as $column) {
            $name = $column['name'] ?? null;

            if (! $name || in_array($name, $skip, true)) {
                continue;
            }

            $tokens = [$this->presence($column, $partial)];

            foreach ($this->constraints($column) as $token) {
                $tokens[] = $token;
            }

            $rules[$name] = implode('|', array_filter($tokens));
        }

        return $rules;
    }

    public function presence(array $column, bool $partial): string
    {
        if ($partial) {
            return ($column['nullable'] ?? false) ? 'sometimes|nullable' : 'sometimes';
        }

        return ($column['nullable'] ?? false) || ($column['default'] ?? null) !== null
            ? 'nullable'
            : 'required';
    }

    /** @return array<int, string> */
    public function constraints(array $column): array
    {
        $type   = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));
        $full   = strtolower((string) ($column['type'] ?? ''));
        $tokens = [];

        if (str_starts_with($full, 'tinyint(1)') || in_array($type, ['boolean', 'bool'], true)) {
            return ['boolean'];
        }

        if (str_contains($type, 'int')) {
            return ['integer'];
        }

        if (in_array($type, ['decimal', 'double', 'float', 'numeric', 'real'], true)) {
            return ['numeric'];
        }

        if (in_array($type, ['date'], true)) {
            return ['date'];
        }

        if (in_array($type, ['datetime', 'timestamp', 'timestamptz'], true)) {
            return ['date'];
        }

        if (in_array($type, ['json', 'jsonb'], true)) {
            return ['array'];
        }

        $tokens[] = 'string';

        if ($length = $this->schema->length((string) ($column['type'] ?? ''))) {
            $tokens[] = 'max:'.$length;
        }

        return $tokens;
    }

    /**
     * A filter group per column type. Only ever a suggestion - which
     * fields may be filtered on is a decision, not a schema detail.
     *
     * @return array<string, string> column => filter group class basename
     */
    public function filters(string $modelClass): array
    {
        $model   = $this->schema->model($modelClass);
        $key     = $model instanceof Model ? $model->getKeyName() : 'id';
        $hidden  = $model instanceof Model ? $model->getHidden() : [];
        $filters = [];

        foreach ($this->columns($modelClass) as $column) {
            $name = $column['name'] ?? null;

            if (! $name || in_array($name, $hidden, true)) {
                continue;
            }

            $filters[$name] = $name === $key
                ? 'IdFilter'
                : $this->filterFor($column);
        }

        return $filters;
    }

    public function filterFor(array $column): string
    {
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));
        $full = strtolower((string) ($column['type'] ?? ''));

        return match (true) {
            str_starts_with($full, 'tinyint(1)'),
            in_array($type, ['boolean', 'bool'], true)                              => 'BooleanFilter',
            str_contains($type, 'int')                                              => 'NumericFilter',
            in_array($type, ['decimal', 'double', 'float', 'numeric', 'real'], true) => 'NumericFilter',
            in_array($type, ['date', 'datetime', 'timestamp', 'timestamptz'], true) => 'DateFilter',
            default                                                                 => 'StringFilter',
        };
    }

    /** Every visible column may be sorted by - hidden ones stay out. */
    public function sortable(string $modelClass): array
    {
        $model   = $this->schema->model($modelClass);
        $hidden  = $model instanceof Model ? $model->getHidden() : [];
        $columns = [];

        foreach ($this->columns($modelClass) as $column) {
            $name = $column['name'] ?? null;

            if ($name && ! in_array($name, $hidden, true)) {
                $columns[] = $name;
            }
        }

        return $columns;
    }
}
