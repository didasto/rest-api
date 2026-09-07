<?php

namespace Didasto\RestApi\Jobs;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Gemeinsame Basis von JobData und JobResult.
 *
 * Beide sind schlichte Objekte mit oeffentlichen Feldern; hin und zurueck
 * uebersetzt wird ueber Reflection, damit beim Anlegen eines neuen Feldes
 * nichts zusaetzlich gepflegt werden muss.
 */
abstract class JobPayload
{
    public static function fromArray(array $values): static
    {
        $instance = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        foreach (static::fields() as $property) {
            $name = $property->getName();

            if (! array_key_exists($name, $values)) {
                if (! $property->isInitialized($instance) && $property->getType()?->allowsNull()) {
                    $property->setValue($instance, null);
                }

                continue;
            }

            $property->setValue($instance, static::cast($property, $values[$name]));
        }

        return $instance;
    }

    public function toArray(): array
    {
        $values = [];

        foreach (static::fields() as $property) {
            $values[$property->getName()] = $property->isInitialized($this)
                ? static::flatten($property->getValue($this))
                : null;
        }

        return $values;
    }

    /** @return array<int, ReflectionProperty> */
    public static function fields(): array
    {
        return array_values(array_filter(
            (new ReflectionClass(static::class))->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $property) => ! $property->isStatic(),
        ));
    }

    public static function cast(ReflectionProperty $property, mixed $value): mixed
    {
        $type = $property->getType();

        if (! $type instanceof ReflectionNamedType || $value === null) {
            return $value;
        }

        $name = $type->getName();

        if (enum_exists($name)) {
            return $value instanceof $name ? $value : $name::from($value);
        }

        if (is_subclass_of($name, self::class) && is_array($value)) {
            return $name::fromArray($value);
        }

        return match ($name) {
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => (bool) $value,
            'string' => (string) $value,
            default  => $value,
        };
    }

    public static function flatten(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_RFC3339_EXTENDED);
        }

        if (is_array($value)) {
            return array_map(static fn ($item) => static::flatten($item), $value);
        }

        return $value;
    }
}
