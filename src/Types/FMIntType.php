<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * A FileMaker number read as an integer. A value which isn't a number at all (a "?", say, or other corrupt data in a
 * field which should hold one) is read as null rather than failing the whole query. Register it in your Doctrine
 * configuration, e.g. as `fmint`.
 */
class FMIntType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        return $value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }

        $number = filter_var($value, FILTER_VALIDATE_FLOAT);

        return false === $number ? null : (int)$number;
    }
}
