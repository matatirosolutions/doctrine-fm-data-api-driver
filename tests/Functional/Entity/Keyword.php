<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Functional\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use MSDev\DoctrineFMDataAPIDriver\Id\FMIdentityGenerator;

/**
 * Maps the API_Keyword layout of the DoctrineFMTest database (see Database/README.md).
 *
 * `ID` is auto-entered by FileMaker (Get ( UUID )), so it is `insertable: false` and the
 * generator fetches it back after the INSERT. The calculated and housekeeping fields are
 * read-only to the driver.
 */
#[ORM\Entity]
#[ORM\Table(name: 'API_Keyword')]
class Keyword
{
    #[ORM\Column(name: 'rec_id', type: 'integer')]
    private ?int $recordId = null;

    #[ORM\Column(name: 'mod_id', type: 'integer')]
    private ?int $modId = null;

    #[ORM\Column(name: 'rec_meta', type: 'string', nullable: true)]
    private ?string $meta = null;

    #[ORM\Id]
    #[ORM\Column(name: 'ID', type: 'string', length: 255, insertable: false)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: FMIdentityGenerator::class)]
    private ?string $id = null;

    #[ORM\Column(name: 'Name', type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(name: 'Category', type: 'string', length: 255, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(name: 'Weight', type: 'integer', nullable: true)]
    private ?int $weight = null;

    #[ORM\Column(name: 'DateValue', type: 'fmdate', nullable: true)]
    private ?DateTime $dateValue = null;

    #[ORM\Column(name: 'TimeValue', type: 'fmtime', nullable: true)]
    private ?DateTime $timeValue = null;

    #[ORM\Column(name: 'Stamp', type: 'fmdatetime', nullable: true)]
    private ?DateTime $stamp = null;

    #[ORM\Column(name: 'Body', type: 'string', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(name: 'Name_calc', type: 'string', nullable: true, insertable: false, updatable: false)]
    private ?string $nameCalc = null;

    #[ORM\Column(name: 'recCreateAccountName', type: 'string', nullable: true, insertable: false, updatable: false)]
    private ?string $createdBy = null;

    public function getRecordId(): ?int { return $this->recordId; }
    public function getModId(): ?int { return $this->modId; }
    public function getMeta(): ?string { return $this->meta; }
    public function getId(): ?string { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }
    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $category): self { $this->category = $category; return $this; }
    public function getWeight(): ?int { return $this->weight; }
    public function setWeight(?int $weight): self { $this->weight = $weight; return $this; }
    public function getDateValue(): ?DateTime { return $this->dateValue; }
    public function setDateValue(?DateTime $dateValue): self { $this->dateValue = $dateValue; return $this; }
    public function getTimeValue(): ?DateTime { return $this->timeValue; }
    public function setTimeValue(?DateTime $timeValue): self { $this->timeValue = $timeValue; return $this; }
    public function getStamp(): ?DateTime { return $this->stamp; }
    public function setStamp(?DateTime $stamp): self { $this->stamp = $stamp; return $this; }
    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $body): self { $this->body = $body; return $this; }
    public function getNameCalc(): ?string { return $this->nameCalc; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
}
