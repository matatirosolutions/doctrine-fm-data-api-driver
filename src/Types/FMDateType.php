<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Types;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;

/**
 * A FileMaker date (month/day/year). Register it in your Doctrine configuration, e.g. as `fmdate`.
 */
class FMDateType extends Type
{
    private const FORMAT = 'm/d/Y';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return date(self::FORMAT, $value);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(self::FORMAT);
        }

        throw InvalidType::new($value, static::class, ['null', 'int', DateTimeInterface::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTime
    {
        if (empty($value)) {
            return null;
        }

        $date = \DateTime::createFromFormat(self::FORMAT, (string)$value);
        if ($date && $date->format(self::FORMAT) === $value) {
            return $date;
        }

        throw InvalidFormat::new((string)$value, static::class, self::FORMAT);
    }
}
