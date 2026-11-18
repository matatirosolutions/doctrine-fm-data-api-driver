<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * The alternative from the README: the application generates the key itself, so it's sent
 * with the INSERT and no custom generator is involved.
 */
#[ORM\Entity]
#[ORM\Table(name: 'Label')]
class KeywordWithAssignedKey
{
    #[ORM\Id]
    #[ORM\Column(name: '__pk_LabelID', type: 'string', length: 255)]
    private string $uuid;

    #[ORM\Column(name: 'name', type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    public function __construct(string $uuid, ?string $name = null)
    {
        $this->uuid = $uuid;
        $this->name = $name;
    }
}
