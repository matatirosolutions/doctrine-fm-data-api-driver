<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Functional;

use DateTime;
use MSDev\DoctrineFMDataAPIDriver\Tests\Functional\Entity\Keyword;
use PHPUnit\Framework\Attributes\DataProvider;

class CrudTest extends FunctionalTestCase
{
    private function keyword(string $name = 'Alpha'): Keyword
    {
        return (new Keyword())->setName($name)->setCategory($this->marker)->setWeight(5);
    }

    public function testInsertGeneratesTheKeyFromFileMaker(): void
    {
        $keyword = $this->keyword();
        $this->em->persist($keyword);
        $this->em->flush();

        self::assertMatchesRegularExpression(
            '/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/i',
            (string) $keyword->getId(),
            'Get ( UUID ) was fetched back after the INSERT'
        );
    }

    public function testRecordCanBeReadBackWithCalculationsAndHousekeeping(): void
    {
        $keyword = $this->keyword('Alpha');
        $this->em->persist($keyword);
        $this->em->flush();
        $id = $keyword->getId();
        $this->em->clear();

        $found = $this->em->find(Keyword::class, $id);

        self::assertNotNull($found);
        self::assertSame('Alpha', $found->getName());
        self::assertSame(5, $found->getWeight());
        self::assertSame('ALPHA', $found->getNameCalc(), 'calculated field');
        self::assertSame(getenv('FM_USER') ?: 'api_test', $found->getCreatedBy(), 'auto-entered creation account');
        self::assertGreaterThan(0, $found->getRecordId());
        self::assertNotNull($found->getModId());
    }

    public function testUpdateChangesTheRecordAndItsModId(): void
    {
        $keyword = $this->keyword('Before');
        $this->em->persist($keyword);
        $this->em->flush();
        $id = $keyword->getId();
        $this->em->clear();

        $loaded = $this->em->find(Keyword::class, $id);
        $modIdBefore = (int) $loaded->getModId();
        $loaded->setName('After')->setWeight(9);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Keyword::class, $id);
        self::assertSame('After', $reloaded->getName());
        self::assertSame(9, $reloaded->getWeight());
        self::assertGreaterThan($modIdBefore, (int) $reloaded->getModId());
    }

    public function testNullIsStoredAsEmptyAndComesBackAsNull(): void
    {
        $keyword = $this->keyword()->setBody('something');
        $this->em->persist($keyword);
        $this->em->flush();
        $id = $keyword->getId();

        $keyword->setBody(null)->setWeight(null);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Keyword::class, $id);
        self::assertNull($reloaded->getBody());
        self::assertNull($reloaded->getWeight());
    }

    public function testDelete(): void
    {
        $keyword = $this->keyword();
        $this->em->persist($keyword);
        $this->em->flush();
        $id = $keyword->getId();

        $this->em->remove($keyword);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->find(Keyword::class, $id));
        self::assertCount(0, $this->rawRecords('API_Keyword'));
    }

    public function testSeveralEntitiesInOneFlush(): void
    {
        foreach (['One', 'Two', 'Three'] as $name) {
            $this->em->persist($this->keyword($name));
        }
        $this->em->flush();

        self::assertCount(3, $this->rawRecords('API_Keyword'));
    }

    public function testTransactionRollbackDiscardsDeferredUpdates(): void
    {
        $keyword = $this->keyword('Keep');
        $this->em->persist($keyword);
        $this->em->flush();
        $id = $keyword->getId();

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $keyword->setName('Discard');
        $this->em->flush();
        $connection->rollBack();
        $this->em->clear();

        self::assertSame('Keep', $this->em->find(Keyword::class, $id)->getName());
    }

    #[DataProvider('awkwardText')]
    public function testAwkwardTextRoundTrips(string $text): void
    {
        $keyword = $this->keyword()->setName($text)->setBody($text);
        $this->em->persist($keyword);
        $this->em->flush();
        $id = $keyword->getId();
        $this->em->clear();

        $reloaded = $this->em->find(Keyword::class, $id);
        self::assertSame($text, $reloaded->getName());
        self::assertSame($text, $reloaded->getBody());
    }

    public static function awkwardText(): iterable
    {
        yield 'quotes' => ['She said "hi" and it\'s fine'];
        yield 'ampersand and angle brackets' => ['Fish & Chips <b>&amp;</b>'];
        yield 'unicode' => ['Māori – café ✓ 日本語'];
        yield 'percent and plus' => ['100% + 50% = 150%'];
        yield 'backslash' => ['C:\\path\\to\\file'];
        yield 'leading and trailing spaces inside' => ['a  b'];
    }

    public function testDateTimeAndTimestampRoundTrip(): void
    {
        $keyword = $this->keyword()
            ->setDateValue(new DateTime('2024-02-29'))
            ->setTimeValue(new DateTime('1970-01-01 13:45:09'))
            ->setStamp(new DateTime('2024-02-29 13:45:09'));
        $this->em->persist($keyword);
        $this->em->flush();
        $id = $keyword->getId();
        $this->em->clear();

        $reloaded = $this->em->find(Keyword::class, $id);
        self::assertSame('2024-02-29', $reloaded->getDateValue()?->format('Y-m-d'));
        self::assertSame('13:45', $reloaded->getTimeValue()?->format('H:i'));
        self::assertSame('2024-02-29 13:45:09', $reloaded->getStamp()?->format('Y-m-d H:i:s'));
    }
}
