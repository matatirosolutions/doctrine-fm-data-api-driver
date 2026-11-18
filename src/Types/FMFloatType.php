<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * A FileMaker number read as a float. A value which isn't a number at all (a "?", say, or other corrupt data in a
 * field which should hold one) is read as null rather than failing the whole query. Register it in your Doctrine
 * configuration, e.g. as `fmfloat`.
 */
class FMFloatType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getFloatDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        return $value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }

        $number = filter_var($value, FILTER_VALIDATE_FLOAT);

        return false === $number ? null : $number;
    }
}
