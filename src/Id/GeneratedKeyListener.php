<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Id;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use MSDev\DoctrineFMDataAPIDriver\Platform\FMPlatform;

/**
 * Makes `#[ORM\GeneratedValue]` (IDENTITY, or AUTO, which resolves to IDENTITY) work for a key which FileMaker
 * assigns, such as a serial number or an auto-enter Get(UUID) field.
 *
 * Doctrine's own IDENTITY generator takes the connection's last insert id, which for the Data API is FileMaker's
 * record id, and casts it to an integer. That's only right when the key *is* the record id (`rec_id`). For any
 * other key column this listener uses FMIdentityGenerator instead, which reads the key back from the record that
 * was created, and keeps the column out of the INSERT, as IDENTITY did.
 *
 * Entities which assign their own key (no GeneratedValue), or which use `rec_id` as the key, are left alone, as are
 * entity managers on other platforms.
 *
 * Register it for the loadClassMetadata event. With DoctrineBundle:
 *
 *     MSDev\DoctrineFMDataAPIDriver\Id\GeneratedKeyListener:
 *         tags: [{ name: doctrine.event_listener, event: loadClassMetadata }]
 */
final class GeneratedKeyListener
{
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $class = $args->getClassMetadata();
        if (!$class->isIdGeneratorIdentity() || $class->isIdentifierComposite) {
            return;
        }

        $field = $class->getSingleIdentifierFieldName();
        if ('rec_id' === $class->getColumnName($field)) {
            return;
        }

        if (!$args->getObjectManager()->getConnection()->getDatabasePlatform() instanceof FMPlatform) {
            return;
        }

        $class->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_CUSTOM);
        $class->setCustomGeneratorDefinition(['class' => FMIdentityGenerator::class]);
        $class->setIdGenerator(new FMIdentityGenerator());
        // IDENTITY kept the key out of the INSERT; a custom generator doesn't, unless the column says so
        $class->fieldMappings[$field]->notInsertable = true;
    }
}
