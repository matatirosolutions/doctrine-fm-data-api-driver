<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * A serial number key which FileMaker assigns, mapped the way 0.x users did: a plain GeneratedValue
 */
#[ORM\Entity]
#[ORM\Table(name: 'Invoice')]
class Invoice
{
    #[ORM\Id]
    #[ORM\Column(name: 'InvoiceID', type: 'integer')]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'total', type: 'float', nullable: true)]
    private ?float $total = null;

    public function getId(): ?int { return $this->id; }
    public function setTotal(?float $total): self { $this->total = $total; return $this; }
}
