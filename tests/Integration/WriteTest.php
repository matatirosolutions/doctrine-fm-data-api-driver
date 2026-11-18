<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Integration;

use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\Keyword;
use MSDev\DoctrineFMDataAPIDriver\Tests\Support\OrmTestCase;

/**
 * persist / flush / remove through the real ORM.
 *
 * Note on the queued responses: UPDATE and DELETE statements first look up the
 * FileMaker record id (a `_find` on the primary key). Because Doctrine wraps a
 * flush in a transaction the driver builds each statement twice, so that lookup
 * currently happens twice - see KnownIssuesTest.
 */
final class WriteTest extends OrmTestCase
{
    private function loaded(array $fields = []): Keyword
    {
        $this->fm->queueRecords([self::record(9, array_merge(
            ['__pk_KeywordID' => 'K9', 'name' => 'old', 'category' => 'x', 'weight' => 1],
            $fields
        ), modId: 4)]);

        $keyword = $this->em->getRepository(Keyword::class)->findOneBy(['name' => 'old']);
        self::assertInstanceOf(Keyword::class, $keyword);

        return $keyword;
    }

    /** The requests made after the initial load */
    private function writes(): array
    {
        return array_slice($this->fm->requests(), 1);
    }

    public function testInsertPostsFieldDataAndReadsBackTheGeneratedKey(): void
    {
        $this->fm->queueResponse(['recordId' => '55', 'modId' => '0']);
        $this->fm->queueRecords([self::record(55, ['__pk_KeywordID' => 'UUID-55'])]);

        $keyword = (new Keyword())->setName('a,b=c')->setCategory('cat')->setWeight(2);
        $this->em->persist($keyword);
        $this->em->flush();

        [$insert, $readBack] = $this->fm->requests();
        self::assertSame('POST', $insert['method']);
        self::assertSame('layouts/Keyword/records', $insert['uri']);
        // pseudo fields and the not-yet-generated primary key are not sent
        self::assertSame(['fieldData' => ['name' => 'a,b=c', 'category' => 'cat', 'weight' => 2]], $insert['body']);

        // FileMaker assigns the primary key (auto-enter calc), so it's fetched back by record id
        self::assertSame('GET', $readBack['method']);
        self::assertSame('layouts/Keyword/records/55', $readBack['uri']);
        self::assertSame('UUID-55', $keyword->getUuid());
    }

    public function testNullsAreWrittenAsEmptyStrings(): void
    {
        $this->fm->queueResponse(['recordId' => '56', 'modId' => '0']);
        $this->fm->queueRecords([self::record(56, ['__pk_KeywordID' => 'UUID-56'])]);

        $this->em->persist((new Keyword())->setName('only-name'));
        $this->em->flush();

        self::assertSame(
            ['fieldData' => ['name' => 'only-name', 'category' => '', 'weight' => '']],
            $this->fm->requests()[0]['body']
        );
    }

    public function testUpdateLooksUpTheRecordIdThenPatchesOnlyChangedFields(): void
    {
        $keyword = $this->loaded();
        $this->fm->queueRecords([self::record(9, ['__pk_KeywordID' => 'K9'])]);
        $this->fm->queueRecords([self::record(9, ['__pk_KeywordID' => 'K9'])]);
        $this->fm->queueResponse(['modId' => '5']);

        $keyword->setName("Somewhere (here) -- ? #x, it's")->setWeight(2);
        $this->em->flush();

        [$lookup, , $patch] = $this->writes();
        self::assertSame('layouts/Keyword/_find', $lookup['uri']);
        self::assertSame(['query' => [['__pk_KeywordID' => 'K9']]], $lookup['body']);
        self::assertSame('PATCH', $patch['method']);
        self::assertSame('layouts/Keyword/records/9', $patch['uri']);
        self::assertSame(['fieldData' => ['name' => "Somewhere (here) -- ? #x, it's", 'weight' => 2]], $patch['body']);
    }

    public function testClearingAFieldPatchesItToAnEmptyString(): void
    {
        $keyword = $this->loaded();
        $this->fm->queueRecords([self::record(9, ['__pk_KeywordID' => 'K9'])]);
        $this->fm->queueRecords([self::record(9, ['__pk_KeywordID' => 'K9'])]);
        $this->fm->queueResponse(['modId' => '5']);

        $keyword->setCategory(null);
        $this->em->flush();

        self::assertSame(['fieldData' => ['category' => '']], $this->writes()[2]['body']);
    }

    public function testUnchangedEntitiesCauseNoWrites(): void
    {
        $this->loaded();

        $this->em->flush();

        self::assertSame([], $this->writes());
    }

    public function testRemoveLooksUpTheRecordIdThenDeletes(): void
    {
        $keyword = $this->loaded();
        $this->fm->queueRecords([self::record(9, ['__pk_KeywordID' => 'K9'])]);
        $this->fm->queueRecords([self::record(9, ['__pk_KeywordID' => 'K9'])]);
        $this->fm->queueResponse([]);

        $this->em->remove($keyword);
        $this->em->flush();

        $delete = $this->writes()[2];
        self::assertSame('DELETE', $delete['method']);
        self::assertSame('layouts/Keyword/records/9', $delete['uri']);
    }
}
