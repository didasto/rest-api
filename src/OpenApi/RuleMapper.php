<?php

namespace Didasto\RestApi\OpenApi;

use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;

/**
 * Translates Laravel validation rules into JSON schema.
 *
 * This is the heart of the documentation: the rules already live in the
 * request classes, so they are also the source of the schema and the two
 * cannot drift apart.
 */
class RuleMapper
{
    /** rule => JSON schema type */
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

    /** rule => JSON schema format */
    public array $formats = [
        'email'       => 'email',
        'url'         => 'uri',
        'uuid'        => 'uuid',
        'ulid'        => 'string',
        'ip'          => 'ipv4',
        'ipv4'        => 'ipv4',
        'ipv6'        => 'ipv6',
        'date'        => 'date',
        'date_format' => 'date-time',
        'file'        => 'binary',
        'image'       => 'binary',
    ];

    /**
     * @param  array<string, mixed>  $rules
     * @return array{type: string, properties: array, required?: array}
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
        $list   = is_string($rule) ? explode('|', $rule) : (array) $rule;
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
        $isArray = $property['type'] === 'array';

        switch ($name) {
            case 'min':
                $property[$numeric ? 'minimum' : ($isArray ? 'minItems' : 'minLength')] = $this->number($argument);
                break;

            case 'max':
                $property[$numeric ? 'maximum' : ($isArray ? 'maxItems' : 'maxLength')] = $this->number($argument);
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
                $table = explode(',', (string) $argument)[0];
                $property['description'] = trim(($property['description'] ?? '').' Must exist in: '.$table);
                break;
        }

        return $property;
    }

    public function number(?string $value): int|float
    {
        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }

    /** Sort nested fields (address.street, tags.*) into place. */
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

            if ($path === []) {
                $schema['properties'][$segment]['items'] = $property;

                return;
            }

            $existing = $schema['properties'][$segment]['items'] ?? ['type' => 'object', 'properties' => []];
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
