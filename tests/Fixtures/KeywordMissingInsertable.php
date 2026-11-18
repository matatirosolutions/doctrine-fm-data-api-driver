<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use MSDev\DoctrineFMDataAPIDriver\Id\FMIdentityGenerator;

/**
 * A mistake: FileMaker generates the key, but the column hasn't been mapped with `insertable: false`
 */
#[ORM\Entity]
#[ORM\Table(name: 'Mistake')]
class KeywordMissingInsertable
{
    #[ORM\Id]
    #[ORM\Column(name: '__pk_MistakeID', type: 'string', length: 255)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: FMIdentityGenerator::class)]
    private ?string $uuid = null;

    #[ORM\Column(name: 'name', type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    public function setName(?string $name): self { $this->name = $name; return $this; }
}
