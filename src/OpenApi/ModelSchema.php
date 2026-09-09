<?php

namespace Didasto\RestApi\OpenApi;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Leitet die Felder eines Models aus den Tabellenspalten ab.
 *
 * Damit steht auch dann ein brauchbares Schema in der Doku, wenn es
 * (noch) keine Store- oder Update-Request gibt - bei einer reinen
 * Lese-API ist das der Normalfall.
 *
 * Die Regeln der Request-Klassen bleiben die genauere Quelle und
 * ueberschreiben, was hier herauskommt.
 */
class ModelSchema
{
    /** Spaltentyp der Datenbank => JSON-Schema-Typ */
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

    /** Spaltentyp der Datenbank => JSON-Schema-Format */
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

    /** Cast am Model => [Typ, Format] - schlaegt den Spaltentyp */
    public array $casts = [
        'int'       => ['integer', null],
        'integer'   => ['integer', null],
        'real'      => ['number', null],
        'float'     => ['number', null],
        'double'    => ['number', null],
        'decimal'   => ['number', null],
        'bool'      => ['boolean', null],
        'boolean'   => ['boolean', null],
        'array'     => ['array', null],
        'json'      => ['object', null],
        'object'    => ['object', null],
        'collection'=> ['array', null],
        'date'      => ['string', 'date'],
        'datetime'  => ['string', 'date-time'],
        'immutable_date'     => ['string', 'date'],
        'immutable_datetime' => ['string', 'date-time'],
        'timestamp' => ['string', 'date-time'],
    ];

    /**
     * @return array<string, array> Feldname => JSON-Schema
     */
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
     * Felder, die der Client nicht setzen darf - Primaerschluessel und
     * Zeitstempel. Ohne Model (oder ohne Datenbank) die ueblichen Namen.
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
     * Ohne Datenbank soll die Doku nicht scheitern - beim Bauen im CI
     * oder bei route:cache steht oft keine Verbindung zur Verfuegung.
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
        return array_filter([
            $model->getKeyName(),
            $model->usesTimestamps() ? $model::CREATED_AT : null,
            $model->usesTimestamps() ? $model::UPDATED_AT : null,
        ]);
    }

    public function property(array $column, mixed $cast): array
    {
        $type = $column['type'] ?? '';
        $name = strtolower((string) ($column['type_name'] ?? $type));

        // tinyint(1) ist in MySQL das Boolean
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

    /** Typen, die nicht in der Tabelle stehen: date, datetime, timestamp, uuid ... */
    public function fromName(string $name): string
    {
        return match (true) {
            str_contains($name, 'int')  => 'integer',
            default                     => 'string',
        };
    }

    /** Der Cast am Model weiss mehr als die Spalte. */
    public function applyCast(array $property, mixed $cast): array
    {
        if (! is_string($cast)) {
            return $this->applyDateFormat($property);
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

            return $property;
        }

        return $this->applyDateFormat($property);
    }

    public function applyDateFormat(array $property): array
    {
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
            'integer' => (int) $default,
            'number'  => (float) $default,
            'boolean' => (bool) $default,
            'object', 'array' => null,
            default   => $default,
        };
    }
}
