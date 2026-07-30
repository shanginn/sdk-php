<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Marshaller\Type;

use Temporal\Internal\Marshaller\MarshallerInterface;

/**
 * Marshals a list of backed-enum cases as their scalar wire values.
 *
 * @template TEnum of \BackedEnum
 * @extends Type<list<int|string>, list<TEnum>>
 */
final class EnumValueArrayType extends Type
{
    /** @var class-string<TEnum> */
    private string $classFQCN;

    /**
     * @param class-string<TEnum>|null $class
     */
    public function __construct(MarshallerInterface $marshaller, ?string $class = null)
    {
        if ($class === null) {
            throw new \RuntimeException('Enum is required.');
        }
        if (!\is_a($class, \BackedEnum::class, true)) {
            throw new \RuntimeException('Class for EnumValueArrayType must be a backed enum.');
        }

        /** @var class-string<TEnum> $enumClass */
        $enumClass = $class;
        $this->classFQCN = $enumClass;
        parent::__construct($marshaller);
    }

    /**
     * @param mixed $value
     * @param mixed $current
     * @return list<TEnum>
     */
    public function parse($value, $current): array
    {
        if ($value === null) {
            return [];
        }

        if (!\is_array($value)) {
            throw new \InvalidArgumentException(
                \sprintf('Passed value must be an array, but %s given.', \get_debug_type($value)),
            );
        }

        $class = $this->classFQCN;
        $result = [];
        foreach ($value as $item) {
            if ($item instanceof $class) {
                $result[] = $item;
                continue;
            }

            if (!\is_int($item) && !\is_string($item)) {
                throw new \InvalidArgumentException(
                    \sprintf('Enum list item must be int or string, but %s given.', \get_debug_type($item)),
                );
            }

            $result[] = $class::from($item);
        }

        return $result;
    }

    /**
     * @param iterable<TEnum> $value
     * @return list<int|string>
     */
    public function serialize($value): array
    {
        $result = [];
        foreach ($value as $item) {
            $result[] = $item->value;
        }

        return $result;
    }
}
