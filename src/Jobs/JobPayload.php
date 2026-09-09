<?php

namespace Didasto\RestApi\Jobs;

use BackedEnum;
use DateTimeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Shared base of JobData and JobResult.
 *
 * Both are plain objects with public fields. Conversion in either
 * direction goes through reflection, so adding a field means adding a
 * property and nothing else.
 */
abstract class JobPayload
{
    public static function fromArray(array $values): static
    {
        $reflection = new ReflectionClass(static::class);
        $instance   = $reflection->newInstanceWithoutConstructor();

        // Without calling the constructor, defaults of promoted parameters
        // never apply: the property would stay uninitialized and the first
        // read would fail. Fill them in here.
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();

            if ($parameter->isPromoted()
                && $parameter->isDefaultValueAvailable()
                && ! array_key_exists($name, $values)) {
                $values[$name] = $parameter->getDefaultValue();
            }
        }

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

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_RFC3339_EXTENDED);
        }

        if (is_array($value)) {
            return array_map(static fn ($item) => static::flatten($item), $value);
        }

        return $value;
    }
}
