<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Types;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;

/**
 * A FileMaker time (hours:minutes). Register it in your Doctrine configuration, e.g. as `fmtime`.
 */
class FMTimeType extends Type
{
    private const FORMAT = 'H:i';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getTimeTypeDeclarationSQL($column);
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

        // The Data API sends times with seconds ("13:45:00"); be lenient about their absence too
        foreach ([self::FORMAT . ':s', self::FORMAT] as $format) {
            $date = \DateTime::createFromFormat($format, (string)$value);
            if ($date && $date->format($format) === $value) {
                return $date;
            }
        }

        // FileMaker will hold anything in a date field if it is set to allow it, and a dud value shouldn't break the
        // whole query: read it as no value
        return null;
    }
}
