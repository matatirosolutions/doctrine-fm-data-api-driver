<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Integration;

use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\Keyword;
use MSDev\DoctrineFMDataAPIDriver\Tests\Support\OrmTestCase;

/**
 * persist / flush / remove through the real ORM.
 *
 * Note on the queued responses: UPDATE and DELETE statements first look up the
 * FileMaker record id (a `_find` on the primary key), then act on that record.
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
        $this->fm->queueResponse(['modId' => '5']);

        $keyword->setName("Somewhere (here) -- ? #x, it's")->setWeight(2);
        $this->em->flush();

        [$lookup, $patch] = $this->writes();
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
        $this->fm->queueResponse(['modId' => '5']);

        $keyword->setCategory(null);
        $this->em->flush();

        self::assertSame(['fieldData' => ['category' => '']], $this->writes()[1]['body']);
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
        $this->fm->queueResponse([]);

        $this->em->remove($keyword);
        $this->em->flush();

        $delete = $this->writes()[1];
        self::assertSame('DELETE', $delete['method']);
        self::assertSame('layouts/Keyword/records/9', $delete['uri']);
    }

    public function testWritesAreHeldBackUntilTheTransactionCommits(): void
    {
        $keyword = $this->loaded();
        $this->fm->queueRecords([self::record(9, ['__pk_KeywordID' => 'K9'])]);
        $this->fm->queueResponse(['modId' => '5']);

        $this->em->getConnection()->beginTransaction();
        $keyword->setName('changed');
        $this->em->flush();
        // flush() ran inside our own, outer transaction, so nothing has been sent yet
        self::assertSame([], $this->writes());

        $this->em->getConnection()->commit();
        self::assertSame(['POST', 'PATCH'], array_column($this->writes(), 'method'));
    }

    public function testRolledBackWritesAreNeverSent(): void
    {
        $keyword = $this->loaded();

        $this->em->getConnection()->beginTransaction();
        $keyword->setName('changed');
        $this->em->flush();
        $this->em->getConnection()->rollBack();

        self::assertSame([], $this->writes());
    }

    public function testInsertsAreSentImmediatelyEvenInsideATransaction(): void
    {
        $this->fm->queueResponse(['recordId' => '57', 'modId' => '0']);
        $this->fm->queueRecords([self::record(57, ['__pk_KeywordID' => 'UUID-57'])]);

        $this->em->getConnection()->beginTransaction();
        $keyword = (new Keyword())->setName('new');
        $this->em->persist($keyword);
        $this->em->flush();

        // the generated key has to be known by the time flush() returns
        self::assertSame('UUID-57', $keyword->getUuid());
        self::assertSame('POST', $this->fm->requests()[0]['method']);
        $this->em->getConnection()->rollBack();
    }

    public function testNestedTransactionsOnlyDropTheirOwnWritesWhenRolledBack(): void
    {
        $keyword = $this->loaded();
        $this->fm->queueRecords([self::record(9, ['__pk_KeywordID' => 'K9'])]);
        $this->fm->queueResponse(['modId' => '5']);

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $keyword->setName('kept');
        $this->em->flush();

        $connection->beginTransaction();
        $keyword->setName('discarded');
        $this->em->flush();
        $connection->rollBack();

        $connection->commit();

        $patches = array_values(array_filter($this->writes(), static fn ($r) => 'PATCH' === $r['method']));
        self::assertSame(['fieldData' => ['name' => 'kept']], $patches[0]['body']);
        self::assertCount(1, $patches);
    }

    public function testWrapInTransactionCommitsTheFlushedWrites(): void
    {
        $keyword = $this->loaded();
        $this->fm->queueRecords([self::record(9, ['__pk_KeywordID' => 'K9'])]);
        $this->fm->queueResponse(['modId' => '5']);

        $this->em->wrapInTransaction(function () use ($keyword): void {
            $keyword->setName('wrapped');
            $this->em->flush();
        });

        self::assertSame(['POST', 'PATCH'], array_column($this->writes(), 'method'));
    }

    public function testReadsInsideATransactionAreNotHeldBack(): void
    {
        $this->fm->queueRecords([self::record(1, ['__pk_KeywordID' => 'K1', 'name' => 'alpha', 'category' => '', 'weight' => 1])]);

        $this->em->getConnection()->beginTransaction();
        $found = $this->em->getRepository(Keyword::class)->findBy(['name' => 'alpha']);
        $this->em->getConnection()->rollBack();

        self::assertCount(1, $found);
    }
}
