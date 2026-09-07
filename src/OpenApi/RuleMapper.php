<?php

namespace Didasto\RestApi\OpenApi;

use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;

/**
 * Uebersetzt Laravel-Validierungsregeln in JSON-Schema.
 *
 * Der Kern der Dokumentation: die Regeln stehen ohnehin schon in den
 * Request-Klassen, also werden sie auch die Quelle fuers Schema.
 */
class RuleMapper
{
    /** Regel => JSON-Schema-Typ */
    public array $types = [
        'integer' => 'integer',
        'int'     => 'integer',
        'numeric' => 'number',
        'decimal' => 'number',
        'boolean' => 'boolean',
        'bool'    => 'boolean',
        'array'   => 'array',
        'string'  => 'string',
        'file'    => 'string',
        'image'   => 'string',
    ];

    /** Regel => JSON-Schema-Format */
    public array $formats = [
        'email'      => 'email',
        'url'        => 'uri',
        'uuid'       => 'uuid',
        'ulid'       => 'string',
        'ip'         => 'ipv4',
        'ipv4'       => 'ipv4',
        'ipv6'       => 'ipv6',
        'date'       => 'date',
        'date_format'=> 'date-time',
        'file'       => 'binary',
        'image'      => 'binary',
    ];

    /**
     * @param  array<string, mixed>  $rules
     * @return array{type: string, properties: array, required: array}
     */
    public function toSchema(array $rules): array
    {
        $schema = ['type' => 'object', 'properties' => [], 'required' => []];

        foreach ($rules as $field => $rule) {
            $tokens = $this->tokens($rule);
            $path   = explode('.', (string) $field);

            $this->put($schema, $path, $this->property($tokens));

            if (count($path) === 1 && in_array('required', $tokens, true)) {
                $schema['required'][] = $path[0];
            }
        }

        if ($schema['required'] === []) {
            unset($schema['required']);
        }

        return $schema;
    }

    /** @return array<int, string> */
    public function tokens(mixed $rule): array
    {
        $list = is_string($rule) ? explode('|', $rule) : (array) $rule;

        $tokens = [];

        foreach ($list as $item) {
            if (is_string($item)) {
                $tokens[] = $item;

                continue;
            }

            if ($item instanceof In || $item instanceof Enum) {
                $tokens[] = (string) $item;

                continue;
            }

            if (is_object($item)) {
                $tokens[] = 'object:'.$item::class;
            }
        }

        return $tokens;
    }

    public function property(array $tokens): array
    {
        $property = ['type' => 'string'];

        foreach ($tokens as $token) {
            [$name, $argument] = array_pad(explode(':', $token, 2), 2, null);
            $name = strtolower($name);

            if (isset($this->types[$name])) {
                $property['type'] = $this->types[$name];
            }

            if (isset($this->formats[$name])) {
                $property['format'] = $this->formats[$name];
            }

            $property = $this->constraint($property, $name, $argument);
        }

        if (in_array('nullable', $tokens, true)) {
            $property['nullable'] = true;
        }

        return $property;
    }

    public function constraint(array $property, string $name, ?string $argument): array
    {
        $numeric = in_array($property['type'], ['integer', 'number'], true);

        switch ($name) {
            case 'min':
                $property[$numeric ? 'minimum' : ($property['type'] === 'array' ? 'minItems' : 'minLength')] = $this->number($argument);
                break;

            case 'max':
                $property[$numeric ? 'maximum' : ($property['type'] === 'array' ? 'maxItems' : 'maxLength')] = $this->number($argument);
                break;

            case 'size':
                $property[$numeric ? 'maximum' : 'maxLength'] = $this->number($argument);
                break;

            case 'between':
                [$from, $to] = array_pad(explode(',', (string) $argument), 2, null);
                $property[$numeric ? 'minimum' : 'minLength'] = $this->number($from);
                $property[$numeric ? 'maximum' : 'maxLength'] = $this->number($to);
                break;

            case 'in':
                $property['enum'] = array_map(
                    fn (string $value) => trim($value, ' "'),
                    explode(',', (string) $argument),
                );
                break;

            case 'digits':
                $property['type']    = 'integer';
                $property['minimum'] = 0;
                break;

            case 'date_format':
                $property['description'] = trim(($property['description'] ?? '').' Format: '.$argument);
                break;

            case 'exists':
                $property['description'] = trim(($property['description'] ?? '').' Muss vorhanden sein in: '.explode(',', (string) $argument)[0]);
                break;
        }

        return $property;
    }

    public function number(?string $value): int|float
    {
        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }

    /** Verschachtelte Felder (adresse.strasse, tags.*) einsortieren. */
    public function put(array &$schema, array $path, array $property): void
    {
        $segment = array_shift($path);

        if ($path === []) {
            $schema['properties'][$segment] = array_merge(
                $schema['properties'][$segment] ?? [],
                $property,
            );

            return;
        }

        if ($path[0] === '*') {
            array_shift($path);

            $schema['properties'][$segment]['type'] = 'array';
            $items = ['type' => 'object', 'properties' => []];

            if ($path === []) {
                $schema['properties'][$segment]['items'] = $property;

                return;
            }

            $existing = $schema['properties'][$segment]['items'] ?? $items;
            $this->put($existing, $path, $property);
            $schema['properties'][$segment]['items'] = $existing;

            return;
        }

        $child = $schema['properties'][$segment] ?? ['type' => 'object', 'properties' => []];
        $child['type'] = 'object';

        $this->put($child, $path, $property);

        $schema['properties'][$segment] = $child;
    }
}
