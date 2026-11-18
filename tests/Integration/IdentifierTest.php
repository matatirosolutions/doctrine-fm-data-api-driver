<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Integration;

use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\Keyword;
use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\KeywordMissingInsertable;
use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\KeywordWithAssignedKey;
use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\Tag;
use MSDev\DoctrineFMDataAPIDriver\Tests\Support\OrmTestCase;

/**
 * The three ways an entity can get its identifier, and what the driver does for each.
 */
final class IdentifierTest extends OrmTestCase
{
    public function testAKeyGeneratedByFileMakerIsLeftOutOfTheInsertAndReadBack(): void
    {
        $this->fm->queueResponse(['recordId' => '55', 'modId' => '0']);
        $this->fm->queueRecords([self::record(55, ['__pk_KeywordID' => 'UUID-55'])]);

        $keyword = (new Keyword())->setName('a');
        $this->em->persist($keyword);
        $this->em->flush();

        [$insert, $readBack] = $this->fm->requests();
        self::assertArrayNotHasKey('__pk_KeywordID', $insert['body']['fieldData']);
        self::assertSame('layouts/Keyword/records/55', $readBack['uri']);
        self::assertSame('UUID-55', $keyword->getUuid());
    }

    public function testTheGeneratedKeyIsUsableStraightAway(): void
    {
        $this->fm->queueResponse(['recordId' => '55', 'modId' => '0']);
        $this->fm->queueRecords([self::record(55, ['__pk_KeywordID' => 'UUID-55'])]);

        $keyword = (new Keyword())->setName('a');
        $this->em->persist($keyword);
        $this->em->flush();

        // it's in the identity map under its key, so this needs no request
        self::assertSame($keyword, $this->em->find(Keyword::class, 'UUID-55'));
        self::assertCount(2, $this->fm->requests());
    }

    public function testSeveralInsertsEachGetTheirOwnKey(): void
    {
        $this->fm->queueResponse(['recordId' => '1', 'modId' => '0']);
        $this->fm->queueRecords([self::record(1, ['__pk_KeywordID' => 'UUID-1'])]);
        $this->fm->queueResponse(['recordId' => '2', 'modId' => '0']);
        $this->fm->queueRecords([self::record(2, ['__pk_KeywordID' => 'UUID-2'])]);

        $first = (new Keyword())->setName('first');
        $second = (new Keyword())->setName('second');
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();

        self::assertSame('UUID-1', $first->getUuid());
        self::assertSame('UUID-2', $second->getUuid());
    }

    public function testAKeyAssignedByTheApplicationIsSentWithTheInsert(): void
    {
        $this->fm->queueResponse(['recordId' => '9', 'modId' => '0']);

        $this->em->persist(new KeywordWithAssignedKey('my-uuid', 'label'));
        $this->em->flush();

        // nothing to read back, so the insert is the only request
        $requests = $this->fm->requests();
        self::assertCount(1, $requests);
        self::assertSame(['fieldData' => ['__pk_LabelID' => 'my-uuid', 'name' => 'label']], $requests[0]['body']);
    }

    public function testTheRecordIdCanBeTheIdentifier(): void
    {
        $this->fm->queueResponse(['recordId' => '321', 'modId' => '0']);

        $tag = (new Tag())->setName('t');
        $this->em->persist($tag);
        $this->em->flush();

        self::assertSame(321, $tag->getId());
        self::assertCount(1, $this->fm->requests(), 'the record id comes with the insert response');
        self::assertSame(['fieldData' => ['name' => 't']], $this->fm->requests()[0]['body']);
    }

    public function testForgettingInsertableFalseIsReportedRatherThanLeavingABlankKey(): void
    {
        $this->fm->queueResponse(['recordId' => '5', 'modId' => '0']);
        // the empty value Doctrine sent replaced the one FileMaker generated
        $this->fm->queueRecords([self::record(5, ['__pk_MistakeID' => '', 'name' => 'x'])]);

        $this->em->persist((new KeywordMissingInsertable())->setName('x'));

        $this->expectException(FMException::class);
        $this->expectExceptionMessage('insertable: false');

        $this->em->flush();
    }

    public function testLastInsertIdWithoutAnInsertIsAnError(): void
    {
        $this->expectException(FMException::class);

        $this->em->getConnection()->lastInsertId();
    }
}
