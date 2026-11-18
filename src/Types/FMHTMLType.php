<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Text which FileMaker stores HTML-encoded. Register it in your Doctrine configuration, e.g. as `fmhtml`.
 */
class FMHTMLType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        return $value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return html_entity_decode((string)$value);
    }
}
