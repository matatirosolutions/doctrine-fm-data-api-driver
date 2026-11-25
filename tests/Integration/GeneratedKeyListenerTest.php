<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Integration;

use Doctrine\ORM\Events;
use MSDev\DoctrineFMDataAPIDriver\Id\FMIdentityGenerator;
use MSDev\DoctrineFMDataAPIDriver\Id\GeneratedKeyListener;
use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\Invoice;
use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\KeywordWithAssignedKey;
use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\Payment;
use MSDev\DoctrineFMDataAPIDriver\Tests\Fixtures\Tag;
use MSDev\DoctrineFMDataAPIDriver\Tests\Support\OrmTestCase;

/**
 * GeneratedValue keys which FileMaker assigns, other than rec_id
 */
final class GeneratedKeyListenerTest extends OrmTestCase
{
    private function listen(): void
    {
        $this->em->getEventManager()->addEventListener(Events::loadClassMetadata, new GeneratedKeyListener());
    }

    public function testWithoutTheListenerTheKeyIsTheRecordId(): void
    {
        // This is what Doctrine's IDENTITY generator does on its own, and why the listener exists
        $this->fm->queueResponse(['recordId' => '7', 'modId' => '0']);

        $invoice = (new Invoice())->setTotal(10.0);
        $this->em->persist($invoice);
        $this->em->flush();

        self::assertSame(7, $invoice->getId());
    }

    public function testASerialNumberKeyIsReadBackAsAnInteger(): void
    {
        $this->listen();
        $this->fm->queueResponse(['recordId' => '7', 'modId' => '0']);
        $this->fm->queueRecords([self::record(7, ['InvoiceID' => '30628', 'total' => 10])]);

        $invoice = (new Invoice())->setTotal(10.0);
        $this->em->persist($invoice);
        $this->em->flush();

        [$insert, $readBack] = $this->fm->requests();
        self::assertSame(['fieldData' => ['total' => 10]], $insert['body'], 'the key is left for FileMaker to assign');
        self::assertSame('layouts/Invoice/records/7', $readBack['uri']);
        self::assertSame(30628, $invoice->getId());
        self::assertSame($invoice, $this->em->find(Invoice::class, 30628), 'it is in the identity map under its key');
    }

    public function testAUuidKeyStaysAString(): void
    {
        $this->listen();
        $this->fm->queueResponse(['recordId' => '8', 'modId' => '0']);
        $this->fm->queueRecords([self::record(8, ['__kp_PaymentID' => '3F2A-UUID', 'amount' => 5])]);

        $payment = (new Payment())->setAmount(5.0);
        $this->em->persist($payment);
        $this->em->flush();

        self::assertSame('3F2A-UUID', $payment->getId());
    }

    public function testTheMetadataIsSwitchedToFMIdentityGenerator(): void
    {
        $this->listen();
        $class = $this->em->getClassMetadata(Invoice::class);

        self::assertSame(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_CUSTOM, $class->generatorType);
        self::assertInstanceOf(FMIdentityGenerator::class, $class->idGenerator);
        self::assertTrue($class->fieldMappings['id']->notInsertable);
    }

    public function testRecIdAsTheKeyIsLeftAlone(): void
    {
        $this->listen();
        $this->fm->queueResponse(['recordId' => '321', 'modId' => '0']);

        $tag = (new Tag())->setName('t');
        $this->em->persist($tag);
        $this->em->flush();

        self::assertTrue($this->em->getClassMetadata(Tag::class)->isIdGeneratorIdentity());
        self::assertSame(321, $tag->getId());
        self::assertCount(1, $this->fm->requests());
    }

    public function testAKeyAssignedByTheApplicationIsLeftAlone(): void
    {
        $this->listen();
        $this->fm->queueResponse(['recordId' => '9', 'modId' => '0']);

        $this->em->persist(new KeywordWithAssignedKey('my-uuid', 'label'));
        $this->em->flush();

        self::assertTrue($this->em->getClassMetadata(KeywordWithAssignedKey::class)->isIdentifierNatural());
        self::assertSame(['fieldData' => ['__pk_LabelID' => 'my-uuid', 'name' => 'label']], $this->fm->requests()[0]['body']);
    }
}
