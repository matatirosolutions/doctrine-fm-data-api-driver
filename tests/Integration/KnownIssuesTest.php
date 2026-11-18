<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Integration;

use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\Keyword;
use MSDev\DoctrineFMDataAPIDriver\Tests\Support\OrmTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Behaviour the 0.x driver gets wrong. Each test states the CORRECT behaviour and
 * is expected to fail today, so the group is excluded from the default run
 * (`vendor/bin/phpunit --group known-bug` to see them fail).
 *
 * They are the acceptance tests for the 1.0 rewrite: when one passes, remove its
 * #[Group] attribute.
 */
#[Group('known-bug')]
final class KnownIssuesTest extends OrmTestCase
{
    public function testCountByCriteriaFiltersOnTheServerAndReturnsTheFoundCount(): void
    {
        // Today: the criteria are dropped (the request is `{"query":[]}`, i.e. everything)
        // and the repository count() blows up reading the result.
        $this->fm->queueRecords([], ['foundCount' => 4, 'returnedCount' => 1, 'totalRecordCount' => 20]);

        $count = $this->em->getRepository(Keyword::class)->count(['category' => 'x']);

        self::assertSame(['query' => [['category' => '==x']]], $this->fm->lastRequest()['body']);
        self::assertSame(4, $count);
    }

    public function testScalarDqlResultsAreSupported(): void
    {
        // Today: FMResult::fetchOne() etc. are `dd()` stubs, which is an undefined function
        // unless symfony/var-dumper happens to be installed.
        $this->fm->queueRecords([], ['foundCount' => 4]);

        $count = $this->em
            ->createQuery(sprintf('SELECT COUNT(k.uuid) FROM %s k WHERE k.category = :c', Keyword::class))
            ->setParameter('c', 'x')
            ->getSingleScalarResult();

        self::assertSame(4, (int) $count);
    }

    public function testUpdateLooksUpTheRecordIdOnlyOnce(): void
    {
        // Today: inside a flush (a transaction) the statement's request is built twice,
        // and building an UPDATE/DELETE request costs a `_find` round trip.
        $this->fm->queueRecords([self::record(9, ['__pk_KeywordID' => 'K9', 'name' => 'old', 'category' => '', 'weight' => 1])]);
        $keyword = $this->em->getRepository(Keyword::class)->findOneBy(['name' => 'old']);

        $this->fm->queueRecords([self::record(9, ['__pk_KeywordID' => 'K9'])]);
        $this->fm->queueResponse(['modId' => '5']);
        $keyword->setName('new');
        $this->em->flush();

        $lookups = array_filter($this->fm->requests(), static fn (array $r) => str_ends_with($r['uri'], '/_find'));
        self::assertCount(2, $lookups, 'one find for the load, one lookup for the update');
    }
}
