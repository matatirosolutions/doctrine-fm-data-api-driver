<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * A Get(UUID) key which FileMaker assigns, mapped with IDENTITY
 */
#[ORM\Entity]
#[ORM\Table(name: 'Payment')]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(name: '__kp_PaymentID', type: 'string', length: 255)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?string $id = null;

    #[ORM\Column(name: 'amount', type: 'float', nullable: true)]
    private ?float $amount = null;

    public function getId(): ?string { return $this->id; }
    public function setAmount(?float $amount): self { $this->amount = $amount; return $this; }
}
