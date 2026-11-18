<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use MSDev\DoctrineFMDataAPIDriver\Id\FMIdentityGenerator;

/**
 * Follows the conventions in the README: the table is the FileMaker layout,
 * rec_id / mod_id are pseudo-fields, and the "real" key is assigned by FileMaker
 * (an auto-enter Get(UUID) calculation), so the column is mapped as not insertable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'Keyword')]
class Keyword
{
    #[ORM\Column(name: 'rec_id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'mod_id', type: 'integer')]
    private ?int $modId = null;

    // JSON describing the query that found the record: found / returned / total counts
    #[ORM\Column(name: 'rec_meta', type: 'string', nullable: true)]
    private ?string $meta = null;

    #[ORM\Id]
    #[ORM\Column(name: '__pk_KeywordID', type: 'string', length: 255, insertable: false)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: FMIdentityGenerator::class)]
    private ?string $uuid = null;

    #[ORM\Column(name: 'name', type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(name: 'category', type: 'string', length: 255, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(name: 'weight', type: 'integer', nullable: true)]
    private ?int $weight = null;

    public function getId(): ?int { return $this->id; }
    public function getModId(): ?int { return $this->modId; }
    public function getMeta(): ?string { return $this->meta; }
    public function getUuid(): ?string { return $this->uuid; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }
    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $category): self { $this->category = $category; return $this; }
    public function getWeight(): ?int { return $this->weight; }
    public function setWeight(?int $weight): self { $this->weight = $weight; return $this; }
}
