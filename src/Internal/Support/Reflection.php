<?php

declare(strict_types=1);

namespace Temporal\Internal\Support;

use Temporal\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class Reflection
{
    /**
     * Sorts the given arguments according to the order of the method's parameters.
     * If the method has default values, they will be used for missing arguments.
     * If the method has no default values, an exception will be thrown.
     *
     * @template T
     *
     * @param \ReflectionFunctionAbstract $method
     * @param array<int|string, T> $args
     * @return list<T> Unnamed list of arguments in the correct order.
     */
    public static function orderArguments(\ReflectionFunctionAbstract $method, array $args): array
    {
        if ($args === [] || \array_is_list($args)) {
            return $args;
        }

        if (count($args) > $method->getNumberOfParameters()) {
            throw new InvalidArgumentException(sprintf(
                'Too many arguments passed to %s, expected %d, got %d.',
                $method->getName(),
                $method->getNumberOfParameters(),
                count($args)
            ));
        }

        $finalArgs = [];


        foreach ($method->getParameters() as $i => $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($i, $args)) {
                if (array_key_exists($name, $args)) {
                    throw new InvalidArgumentException(sprintf(
                        'Argument #%d $%s passed to %s as positional and as named at the same time',
                        $i,
                        $name,
                        $method->getName(),
                    ));
                }

                $finalArgs[$name] = $args[$i];
            } elseif (array_key_exists($name, $args)) {
                $finalArgs[$name] = $args[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $finalArgs[$name] = $parameter->getDefaultValue();
            } else {
                throw new InvalidArgumentException("Missing argument `$name`.");
            }
        }

        return array_values($finalArgs);
    }
}
