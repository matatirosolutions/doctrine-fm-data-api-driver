<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Unit;

use DateTime;
use MSDev\DoctrineFMDataAPIDriver\Platform\FMPlatform;
use MSDev\DoctrineFMDataAPIDriver\Types\FMDateTimeType;
use MSDev\DoctrineFMDataAPIDriver\Types\FMFloatType;
use MSDev\DoctrineFMDataAPIDriver\Types\FMIntType;
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

    public function testUnreadableDatesAndTimesAreNullRatherThanAnError(): void
    {
        self::assertNull((new FMTimeType())->convertToPHPValue('quarter past one', $this->platform));
        self::assertNull((new FMDateType())->convertToPHPValue('2024-02-29', $this->platform));
        self::assertNull((new FMDateType())->convertToPHPValue('13/45/2024', $this->platform));
        self::assertNull((new FMDateTimeType())->convertToPHPValue('02/29/2024', $this->platform));
    }

    public function testIntegersAreReadLeniently(): void
    {
        $type = new FMIntType();

        self::assertSame(42, $type->convertToPHPValue('42', $this->platform));
        self::assertSame(42, $type->convertToPHPValue(42, $this->platform));
        self::assertSame(42, $type->convertToPHPValue('42.9', $this->platform), 'a float string is truncated');
        self::assertSame(0, $type->convertToPHPValue('0', $this->platform), 'zero is a value');
        self::assertSame(0, $type->convertToPHPValue(0, $this->platform));
        self::assertNull($type->convertToPHPValue('?', $this->platform), 'corrupt data');
        self::assertNull($type->convertToPHPValue('', $this->platform));
        self::assertNull($type->convertToPHPValue(null, $this->platform));
        self::assertSame('7', $type->convertToDatabaseValue('7', $this->platform));
    }

    public function testFloatsAreReadLeniently(): void
    {
        $type = new FMFloatType();

        self::assertSame(1.5, $type->convertToPHPValue('1.5', $this->platform));
        self::assertSame(3.0, $type->convertToPHPValue(3, $this->platform));
        self::assertSame(0.0, $type->convertToPHPValue('0', $this->platform), 'zero is a value');
        self::assertNull($type->convertToPHPValue('n/a', $this->platform), 'corrupt data');
        self::assertNull($type->convertToPHPValue('', $this->platform));
        self::assertNull($type->convertToPHPValue(null, $this->platform));
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
