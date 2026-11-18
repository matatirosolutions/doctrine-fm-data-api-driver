<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Functional;

use MSDev\DoctrineFMDataAPIDriver\Tests\Functional\Entity\Keyword;
use MSDev\DoctrineFMDataAPIDriver\Tests\Functional\Entity\Note;

class QueryTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['Apple', 1], ['Banana', 2], ['Cherry', 3], ['Date', 4], ['Elderberry', 5]] as [$name, $weight]) {
            $this->em->persist((new Keyword())->setName($name)->setCategory($this->marker)->setWeight($weight));
        }
        $this->em->flush();
        $this->em->clear();
    }

    private function names(array $keywords): array
    {
        return array_map(static fn(Keyword $k) => $k->getName(), $keywords);
    }

    public function testFindByExactMatch(): void
    {
        $found = $this->em->getRepository(Keyword::class)->findBy(['category' => $this->marker, 'name' => 'Banana']);

        self::assertSame(['Banana'], $this->names($found));
    }

    public function testFindOneByAndNoMatch(): void
    {
        $repo = $this->em->getRepository(Keyword::class);

        self::assertSame('Cherry', $repo->findOneBy(['category' => $this->marker, 'name' => 'Cherry'])?->getName());
        self::assertNull($repo->findOneBy(['category' => $this->marker, 'name' => 'Nonesuch']));
        self::assertSame([], $repo->findBy(['category' => $this->marker, 'name' => 'Nonesuch']));
    }

    public function testExactMatchIsNotAPrefixMatch(): void
    {
        self::assertSame(
            [],
            $this->em->getRepository(Keyword::class)->findBy(['category' => $this->marker, 'name' => 'App'])
        );
    }

    public function testInList(): void
    {
        $found = $this->em->getRepository(Keyword::class)
            ->findBy(['category' => $this->marker, 'name' => ['Apple', 'Date', 'Nonesuch']], ['name' => 'ASC']);

        self::assertSame(['Apple', 'Date'], $this->names($found));
    }

    public function testOrderingLimitAndOffset(): void
    {
        $repo = $this->em->getRepository(Keyword::class);

        self::assertSame(
            ['Elderberry', 'Date', 'Cherry', 'Banana', 'Apple'],
            $this->names($repo->findBy(['category' => $this->marker], ['weight' => 'DESC']))
        );
        self::assertSame(
            ['Banana', 'Cherry'],
            $this->names($repo->findBy(['category' => $this->marker], ['weight' => 'ASC'], 2, 1))
        );
    }

    public function testComparisons(): void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('k')->from(Keyword::class, 'k')
            ->where('k.category = :c')->andWhere('k.weight > :w')
            ->setParameter('c', $this->marker)->setParameter('w', 3)
            ->orderBy('k.weight', 'ASC');

        self::assertSame(['Date', 'Elderberry'], $this->names($qb->getQuery()->getResult()));
    }

    public function testRecordMetadataReportsWhatWasFoundFetchedAndHeld(): void
    {
        $found = $this->em->getRepository(Keyword::class)->findBy(['category' => $this->marker], ['weight' => 'ASC'], 2);

        $meta = json_decode($found[0]->getMeta(), true);
        self::assertSame(5, $meta['found']);
        self::assertSame(2, $meta['fetch']);
        self::assertGreaterThanOrEqual(5, $meta['total'], 'every record in the table');
    }

    public function testCount(): void
    {
        $repo = $this->em->getRepository(Keyword::class);

        self::assertSame(5, $repo->count(['category' => $this->marker]));
        self::assertSame(1, $repo->count(['category' => $this->marker, 'name' => 'Apple']));
        self::assertSame(0, $repo->count(['category' => $this->marker, 'name' => 'Nonesuch']));
    }

    public function testRecordsOfAnotherTableAreRelatedByKey(): void
    {
        $apple = $this->em->getRepository(Keyword::class)->findOneBy(['category' => $this->marker, 'name' => 'Apple']);
        foreach (['first', 'second'] as $ignored) {
            // The Text field carries the marker so that tearDown can find the notes
            $this->em->persist(new Note($apple->getId(), $this->marker));
        }
        $this->em->flush();
        $this->em->clear();

        $notes = $this->em->getRepository(Note::class)->findBy(['keywordId' => $apple->getId()]);

        self::assertCount(2, $notes);
        self::assertSame($this->marker, $notes[0]->getText());
    }
}
