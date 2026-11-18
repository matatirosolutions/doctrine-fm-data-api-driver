<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Integration;

use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\Keyword;
use MSDev\DoctrineFMDataAPIDriver\Tests\Support\OrmTestCase;

/**
 * Doctrine query -> Data API request, and Data API records -> entities, through
 * the real ORM. These pin the observable behaviour of the 0.x driver so it can be
 * carried across to the DBAL 4 / ORM 3 rewrite.
 */
final class ReadTest extends OrmTestCase
{
    private function dql(string $where): \Doctrine\ORM\Query
    {
        return $this->em->createQuery(sprintf('SELECT k FROM %s k WHERE %s', Keyword::class, $where));
    }

    public function testRecordsAreHydratedUsingThePseudoFields(): void
    {
        $this->fm->queueRecords([
            self::record(7, ['__pk_KeywordID' => 'K7', 'name' => 'alpha', 'category' => '', 'weight' => 3], modId: 2),
        ]);

        [$keyword] = $this->em->getRepository(Keyword::class)->findAll();

        self::assertSame(7, $keyword->getId());      // rec_id  <- recordId
        self::assertSame(2, $keyword->getModId());   // mod_id  <- modId
        self::assertSame('K7', $keyword->getUuid());
        self::assertSame('alpha', $keyword->getName());
        self::assertNull($keyword->getCategory(), 'empty FileMaker fields are hydrated as null');
        self::assertSame(3, $keyword->getWeight());
    }

    public function testFindAllUsesTheRecordsEndpointWithAHighLimit(): void
    {
        $this->fm->queueRecords([]);

        $this->em->getRepository(Keyword::class)->findAll();

        $req = $this->fm->lastRequest();
        self::assertSame('GET', $req['method']);
        self::assertSame('layouts/Keyword/records', $req['uri']);
        // The Data API defaults to 100 records, so the driver asks for a lot more
        self::assertSame(['_offset' => '1', '_limit' => '10000'], $req['query']);
    }

    public function testNoRecordsFoundYieldsAnEmptyResult(): void
    {
        $this->fm->queueError(401, 'No records match the request', 404);

        self::assertSame([], $this->em->getRepository(Keyword::class)->findBy(['name' => 'nope']));
    }

    public function testFindByASingleCriterionIsAnExactMatchFind(): void
    {
        $this->fm->queueRecords([]);

        $this->em->getRepository(Keyword::class)->findBy(['name' => 'alpha']);

        $req = $this->fm->lastRequest();
        self::assertSame('POST', $req['method']);
        self::assertSame('layouts/Keyword/_find', $req['uri']);
        self::assertSame(['query' => [['name' => '==alpha']]], $req['body']);
    }

    public function testMultipleCriteriaAreCombinedWithAnd(): void
    {
        $this->fm->queueRecords([]);

        $this->em->getRepository(Keyword::class)->findBy(['name' => 'alpha', 'category' => 'x']);

        self::assertSame(['query' => [['name' => '==alpha', 'category' => '==x']]], $this->fm->lastRequest()['body']);
    }

    public function testAnArrayCriterionBecomesOneFindRequestPerValue(): void
    {
        $this->fm->queueRecords([]);

        $this->em->getRepository(Keyword::class)->findBy(['category' => ['a', 'b', 'c']]);

        self::assertSame(
            ['query' => [['category' => '==a'], ['category' => '==b'], ['category' => '==c']]],
            $this->fm->lastRequest()['body']
        );
    }

    public function testNotEqualsBecomesAnOmitRequest(): void
    {
        $this->fm->queueRecords([]);

        $this->dql('k.category <> :c')->setParameter('c', 'x')->getResult();

        self::assertSame(['query' => [['category' => 'x', 'omit' => 'true']]], $this->fm->lastRequest()['body']);
    }

    public function testComparisonOperatorsArePassedThroughToFileMaker(): void
    {
        $this->fm->queueRecords([]);

        $this->dql('k.weight > :w')->setParameter('w', 5)->getResult();

        self::assertSame(['query' => [['weight' => '>5']]], $this->fm->lastRequest()['body']);
    }

    public function testLikeValuesAreSentUntouchedSoFileMakerWildcardsApply(): void
    {
        $this->fm->queueRecords([]);

        $this->dql('k.name LIKE :n')->setParameter('n', 'al*')->getResult();

        self::assertSame(['query' => [['name' => 'al*']]], $this->fm->lastRequest()['body']);
    }

    public function testOrConditionsBecomeSeparateFindRequests(): void
    {
        $this->fm->queueRecords([]);

        $this->dql('(k.name = :a OR k.name = :b)')->setParameter('a', 'x')->setParameter('b', 'y')->getResult();

        self::assertSame(['query' => [['name' => '==x'], ['name' => '==y']]], $this->fm->lastRequest()['body']);
    }

    public function testSortLimitAndOffsetWithoutCriteriaUseTheRecordsEndpoint(): void
    {
        $this->fm->queueRecords([]);

        $this->em->getRepository(Keyword::class)->findBy([], ['name' => 'ASC', 'weight' => 'DESC'], 5, 10);

        $req = $this->fm->lastRequest();
        self::assertSame('GET', $req['method']);
        self::assertSame('layouts/Keyword/records', $req['uri']);
        self::assertSame(
            [['fieldName' => 'name', 'sortOrder' => 'ascend'], ['fieldName' => 'weight', 'sortOrder' => 'descend']],
            json_decode($req['query']['_sort'], true)
        );
        // Doctrine's offset is zero based, FileMaker's is one based
        self::assertSame('11', $req['query']['_offset']);
        self::assertSame('5', $req['query']['_limit']);
    }

    public function testSortLimitAndOffsetWithCriteriaAreSentInTheFindBody(): void
    {
        $this->fm->queueRecords([]);

        $this->em->getRepository(Keyword::class)->findBy(['category' => 'x'], ['name' => 'ASC'], 5, 10);

        self::assertSame([
            'query' => [['category' => '==x']],
            'sort' => [['fieldName' => 'name', 'sortOrder' => 'ascend']],
            'offset' => 11,
            'limit' => 5,
        ], $this->fm->lastRequest()['body']);
    }

    public function testFindOneByLimitsTheFindToASingleRecord(): void
    {
        $this->fm->queueRecords([self::record(1, ['__pk_KeywordID' => 'K1', 'name' => 'alpha', 'category' => '', 'weight' => 1])]);

        $keyword = $this->em->getRepository(Keyword::class)->findOneBy(['name' => 'alpha']);

        self::assertInstanceOf(Keyword::class, $keyword);
        self::assertSame(['query' => [['name' => '==alpha']], 'offset' => 1, 'limit' => 1], $this->fm->lastRequest()['body']);
    }

    public function testFindByPrimaryKeyFindsOnThePrimaryKeyField(): void
    {
        $this->fm->queueRecords([self::record(1, ['__pk_KeywordID' => 'K1', 'name' => 'alpha', 'category' => '', 'weight' => 1])]);

        $this->em->find(Keyword::class, 'K1');

        self::assertSame('layouts/Keyword/_find', $this->fm->lastRequest()['uri']);
        self::assertSame('==K1', $this->fm->lastRequest()['body']['query'][0]['__pk_KeywordID']);
    }

    public function testSpecialCharactersInValuesReachFileMakerIntact(): void
    {
        $this->fm->queueRecords([]);
        $nasty = "Somewhere (here) -- ? #x `y` union where rename @z, it's";

        $this->em->getRepository(Keyword::class)->findBy(['name' => $nasty]);

        self::assertSame(['query' => [['name' => '==' . $nasty]]], $this->fm->lastRequest()['body']);
    }

    public function testBooleanFalseIsSentAsZero(): void
    {
        $this->fm->queueRecords([]);

        $this->em->getRepository(Keyword::class)->findBy(['weight' => false]);

        self::assertSame(['query' => [['weight' => '==0']]], $this->fm->lastRequest()['body']);
    }

    public function testCountByCriteriaFiltersOnTheServerAndReturnsTheFoundCount(): void
    {
        $this->fm->queueRecords([self::record(1, ['__pk_KeywordID' => 'K1'])], ['foundCount' => 4, 'returnedCount' => 1, 'totalRecordCount' => 20]);

        $count = $this->em->getRepository(Keyword::class)->count(['category' => 'x']);

        // Only the count is wanted, so a single record is requested
        self::assertSame(['query' => [['category' => '==x']], 'limit' => 1], $this->fm->lastRequest()['body']);
        self::assertSame(4, $count);
    }

    public function testCountWithoutCriteriaUsesTheRecordsEndpoint(): void
    {
        $this->fm->queueRecords([], ['foundCount' => 20, 'returnedCount' => 1, 'totalRecordCount' => 20]);

        $count = $this->em->getRepository(Keyword::class)->count([]);

        $req = $this->fm->lastRequest();
        self::assertSame('GET', $req['method']);
        self::assertSame(['_offset' => '1', '_limit' => '1'], $req['query']);
        self::assertSame(20, $count);
    }

    public function testCountIsZeroWhenNothingMatches(): void
    {
        $this->fm->queueError(401, 'No records match the request', 404);

        self::assertSame(0, $this->em->getRepository(Keyword::class)->count(['category' => 'nope']));
    }

    public function testScalarDqlCount(): void
    {
        $this->fm->queueRecords([], ['foundCount' => 4]);

        $count = $this->em
            ->createQuery(sprintf('SELECT COUNT(k.uuid) FROM %s k WHERE k.category = :c', Keyword::class))
            ->setParameter('c', 'x')
            ->getSingleScalarResult();

        self::assertSame(4, (int)$count);
    }

    public function testRecordMetadataIsAvailableThroughThePseudoField(): void
    {
        $this->fm->queueRecords(
            [self::record(7, ['__pk_KeywordID' => 'K7', 'name' => 'a', 'category' => 'b', 'weight' => 1])],
            ['foundCount' => 3, 'returnedCount' => 1, 'totalRecordCount' => 30]
        );

        [$keyword] = $this->em->getRepository(Keyword::class)->findBy(['name' => 'a']);

        self::assertSame(
            ['found' => 3, 'fetch' => 1, 'total' => 30],
            json_decode($keyword->getMeta(), true)
        );
    }
}
