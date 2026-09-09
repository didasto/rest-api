<?php

namespace Didasto\RestApi\OpenApi;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Derives the fields of a model from its table columns.
 *
 * This gives a usable schema even when there is no store or update
 * request yet, which is the normal case for a read only API. The rules of
 * the request classes remain the more precise source and override
 * whatever comes out of here.
 */
class ModelSchema
{
    /** database column type => JSON schema type */
    public array $types = [
        'bigint'    => 'integer',
        'int'       => 'integer',
        'integer'   => 'integer',
        'mediumint' => 'integer',
        'smallint'  => 'integer',
        'tinyint'   => 'integer',
        'decimal'   => 'number',
        'double'    => 'number',
        'float'     => 'number',
        'numeric'   => 'number',
        'real'      => 'number',
        'boolean'   => 'boolean',
        'bool'      => 'boolean',
        'json'      => 'object',
        'jsonb'     => 'object',
    ];

    /** database column type => JSON schema format */
    public array $formats = [
        'date'        => 'date',
        'datetime'    => 'date-time',
        'timestamp'   => 'date-time',
        'timestamptz' => 'date-time',
        'time'        => 'time',
        'uuid'        => 'uuid',
        'binary'      => 'binary',
        'blob'        => 'binary',
    ];

    /** model cast => [type, format] - takes precedence over the column type */
    public array $casts = [
        'int'                => ['integer', null],
        'integer'            => ['integer', null],
        'real'               => ['number', null],
        'float'              => ['number', null],
        'double'             => ['number', null],
        'decimal'            => ['number', null],
        'bool'               => ['boolean', null],
        'boolean'            => ['boolean', null],
        'array'              => ['array', null],
        'json'               => ['object', null],
        'object'             => ['object', null],
        'collection'         => ['array', null],
        'date'               => ['string', 'date'],
        'datetime'           => ['string', 'date-time'],
        'immutable_date'     => ['string', 'date'],
        'immutable_datetime' => ['string', 'date-time'],
        'timestamp'          => ['string', 'date-time'],
    ];

    /** @return array<string, array> field name => JSON schema */
    public function properties(string $modelClass): array
    {
        $model = $this->model($modelClass);

        if (! $model) {
            return [];
        }

        $columns = $this->columns($model);

        if ($columns === []) {
            return [];
        }

        $hidden     = $model->getHidden();
        $casts      = $model->getCasts();
        $readOnly   = $this->readOnlyFields($model);
        $properties = [];

        foreach ($columns as $column) {
            $name = $column['name'] ?? null;

            if (! $name || in_array($name, $hidden, true)) {
                continue;
            }

            $property = $this->property($column, $casts[$name] ?? null);

            if (in_array($name, $readOnly, true)) {
                $property['readOnly'] = true;
            }

            $properties[$name] = $property;
        }

        return $properties;
    }

    /**
     * Fields the caller must not set: the primary key and the timestamps.
     * Without a model, or without a database, the usual names.
     *
     * @return array<int, string>
     */
    public function readOnly(string $modelClass): array
    {
        $model = $this->model($modelClass);

        return $model
            ? $this->readOnlyFields($model)
            : ['id', 'created_at', 'updated_at'];
    }

    public function model(string $modelClass): ?Model
    {
        try {
            $model = new $modelClass();

            return $model instanceof Model ? $model : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The documentation must not fail without a database - during a build
     * or a route:cache there is often no connection available.
     */
    public function columns(Model $model): array
    {
        try {
            return $model->getConnection()
                ->getSchemaBuilder()
                ->getColumns($model->getTable());
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, string> */
    public function readOnlyFields(Model $model): array
    {
        return array_values(array_filter([
            $model->getKeyName(),
            $model->usesTimestamps() ? $model::CREATED_AT : null,
            $model->usesTimestamps() ? $model::UPDATED_AT : null,
        ]));
    }

    public function property(array $column, mixed $cast): array
    {
        $type = (string) ($column['type'] ?? '');
        $name = strtolower((string) ($column['type_name'] ?? $type));

        // In MySQL, tinyint(1) is the boolean.
        $property = ['type' => str_starts_with(strtolower($type), 'tinyint(1)')
            ? 'boolean'
            : ($this->types[$name] ?? $this->fromName($name))];

        if (isset($this->formats[$name])) {
            $property['format'] = $this->formats[$name];
        }

        if ($length = $this->length($type)) {
            $property['maxLength'] = $length;
        }

        if (($column['nullable'] ?? false) === true) {
            $property['nullable'] = true;
        }

        $default = $this->defaultValue($column, $property['type']);

        if ($default !== null) {
            $property['default'] = $default;
        }

        return $this->applyCast($property, $cast);
    }

    public function fromName(string $name): string
    {
        return str_contains($name, 'int') ? 'integer' : 'string';
    }

    /** The cast on the model knows more than the column does. */
    public function applyCast(array $property, mixed $cast): array
    {
        if (! is_string($cast)) {
            return $property;
        }

        $base = strtolower(explode(':', $cast, 2)[0]);

        if (isset($this->casts[$base])) {
            [$type, $format] = $this->casts[$base];

            $property['type'] = $type;

            if ($format) {
                $property['format'] = $format;
            }

            unset($property['maxLength']);

            return $property;
        }

        if (enum_exists($cast)) {
            $cases = $cast::cases();

            $property['enum'] = is_subclass_of($cast, BackedEnum::class)
                ? array_column($cases, 'value')
                : array_column($cases, 'name');
        }

        return $property;
    }

    public function length(string $type): ?int
    {
        return preg_match('/^(?:var)?char\((\d+)\)/i', $type, $match)
            ? (int) $match[1]
            : null;
    }

    public function defaultValue(array $column, string $type): int|float|bool|string|null
    {
        $default = $column['default'] ?? null;

        if ($default === null || $default === '' || str_contains(strtoupper((string) $default), 'CURRENT_')) {
            return null;
        }

        $default = trim((string) $default, "'\"");

        return match ($type) {
            'integer'         => (int) $default,
            'number'          => (float) $default,
            'boolean'         => (bool) $default,
            'object', 'array' => null,
            default           => $default,
        };
    }
}
