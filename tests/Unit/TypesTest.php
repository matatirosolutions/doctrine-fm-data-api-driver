<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Unit;

use DateTime;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use MSDev\DoctrineFMDataAPIDriver\Platform\FMPlatform;
use MSDev\DoctrineFMDataAPIDriver\Types\FMDateTimeType;
use MSDev\DoctrineFMDataAPIDriver\Types\FMDateType;
use MSDev\DoctrineFMDataAPIDriver\Types\FMTimeType;
use PHPUnit\Framework\TestCase;

class TypesTest extends TestCase
{
    private FMPlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new FMPlatform();
    }

    public function testTimeReadsWithOrWithoutSeconds(): void
    {
        $type = new FMTimeType();

        self::assertSame('13:45:09', $type->convertToPHPValue('13:45:09', $this->platform)->format('H:i:s'));
        self::assertSame('13:45', $type->convertToPHPValue('13:45', $this->platform)->format('H:i'));
        self::assertNull($type->convertToPHPValue('', $this->platform));
        self::assertNull($type->convertToPHPValue(null, $this->platform));
    }

    public function testTimeRejectsNonsense(): void
    {
        $this->expectException(InvalidFormat::class);
        (new FMTimeType())->convertToPHPValue('quarter past one', $this->platform);
    }

    public function testDateAndTimestampUseTheDataApiFormats(): void
    {
        self::assertSame('02/29/2024', (new FMDateType())->convertToDatabaseValue(new DateTime('2024-02-29'), $this->platform));
        self::assertSame('2024-02-29', (new FMDateType())->convertToPHPValue('02/29/2024', $this->platform)->format('Y-m-d'));
        self::assertSame(
            '02/29/2024 13:45:09',
            (new FMDateTimeType())->convertToDatabaseValue(new DateTime('2024-02-29 13:45:09'), $this->platform)
        );
        self::assertSame(
            '2024-02-29 13:45:09',
            (new FMDateTimeType())->convertToPHPValue('02/29/2024 13:45:09', $this->platform)->format('Y-m-d H:i:s')
        );
    }
}
