<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Type;

/**
 * A PHP array stored as base64 encoded serialized text. Register it in your Doctrine
 * configuration under a name of your choosing, e.g. `fmarray`.
 */
class FMArrayType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string
    {
        return base64_encode(serialize($value));
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (null === $value) {
            return null;
        }

        $value = is_resource($value) ? stream_get_contents($value) : $value;
        $val = unserialize(base64_decode((string)$value));
        if (false === $val && 'b:0;' !== $value) {
            throw InvalidFormat::new((string)$value, static::class, 'a base64 encoded serialized array');
        }

        return $val;
    }
}
