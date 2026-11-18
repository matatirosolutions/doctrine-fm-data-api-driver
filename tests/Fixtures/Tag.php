<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * Uses FileMaker's own record id as the identifier
 */
#[ORM\Entity]
#[ORM\Table(name: 'Tag')]
class Tag
{
    #[ORM\Id]
    #[ORM\Column(name: 'rec_id', type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    public function getId(): ?int { return $this->id; }
    public function setName(?string $name): self { $this->name = $name; return $this; }
}
