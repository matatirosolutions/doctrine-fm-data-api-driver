<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Id;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Id\AbstractIdGenerator;
use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use Throwable;

/**
 * Id generator for identifiers which FileMaker assigns on creation (typically an auto-enter
 * Get(UUID) calculation).
 *
 * After the INSERT it reads the identifier field back from the record which was created. For that to work the
 * identifier column must be mapped with `insertable: false`, so that Doctrine doesn't send an empty value
 * which would replace the one FileMaker generated.
 *
 * If the identifier is the rec_id pseudo field then FileMaker's record id is used and nothing needs to be fetched.
 *
 * Unlike Doctrine's IDENTITY generator, which casts every identifier to an integer, the value is converted by the
 * identifier field's own type, so a Get(UUID) key stays a string and a serial number becomes an int.
 *
 * GeneratedKeyListener applies this generator to `#[ORM\GeneratedValue]` keys automatically.
 */
class FMIdentityGenerator extends AbstractIdGenerator
{
    /**
     * @param string|null $sequenceName Unused, accepted so existing mappings continue to work
     */
    public function __construct(private readonly ?string $sequenceName = null)
    {
    }

    /**
     * @throws FMException
     */
    public function generateId(EntityManagerInterface $em, ?object $entity): mixed
    {
        if (null === $entity) {
            throw new FMException('An entity is needed to work out which field holds its identifier');
        }

        $connection = $em->getConnection();
        $recordId = (string)$connection->lastInsertId();

        $class = $em->getClassMetadata($entity::class);
        try {
            $idColumn = $class->getSingleIdentifierColumnName();
        } catch (Throwable $e) {
            throw new FMException(sprintf('%s must have a single identifier column to use FMIdentityGenerator', $class->getName()), 0, $e);
        }

        if ('rec_id' === $idColumn) {
            return $this->asIdentifier($recordId, $class, $connection);
        }

        $uri = sprintf('layouts/%s/records/%s', $class->getTableName(), $recordId);
        try {
            $record = $connection->getNativeConnection()->performFMRequest('GET', $uri, []);
        } catch (Throwable $e) {
            throw new FMException('Unable to locate record primary key with error ' . $e->getMessage(), 0, $e);
        }

        $id = $record[0]['fieldData'][$idColumn] ?? null;
        if (null === $id || '' === $id) {
            throw new FMException(sprintf(
                'The record created for %1$s (record id %2$s) has no value in "%3$s". If FileMaker generates that value, map the '
                . 'column with `insertable: false` so that an empty value isn\'t sent which would replace it.',
                $class->getName(),
                $recordId,
                $idColumn
            ));
        }

        return $this->asIdentifier($id, $class, $connection);
    }

    /**
     * Converts the value as its field's type would, so that an integer serial number arrives as an int
     */
    private function asIdentifier(mixed $value, ClassMetadata $class, Connection $connection): mixed
    {
        $type = $class->getFieldMapping($class->getSingleIdentifierFieldName())->type;

        return Type::getType($type)->convertToPHPValue((string)$value, $connection->getDatabasePlatform());
    }

    public function isPostInsertGenerator(): bool
    {
        return true;
    }
}
