<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Functional;

use MSDev\DoctrineFMDataAPIDriver\Exception\FMException;
use MSDev\DoctrineFMDataAPIDriver\Tests\Functional\Entity\Keyword;
use MSDev\DoctrineFMDataAPIDriver\Utility\ContainerAccess;
use MSDev\DoctrineFMDataAPIDriver\Utility\ScriptAccess;
use MSDev\DoctrineFMDataAPIDriver\Utility\SetGlobals;

class HelperTest extends FunctionalTestCase
{
    /** The script has spaces and brackets in its name, which the caller has to encode */
    private const ECHO_SCRIPT = 'Echo%20Parameter%20(%20json%20)';

    public function testExecuteScriptReturnsWhatTheScriptExitedWith(): void
    {
        $result = (new ScriptAccess($this->connection()))
            ->executeScript('Blank_Keyword', self::ECHO_SCRIPT, rawurlencode('hello'));

        self::assertSame('0', (string) $result['error']);
        $decoded = json_decode($result['result'], true);
        self::assertSame(0, $decoded['errorCode']);
        self::assertSame('hello', $decoded['data']);
    }

    public function testUnknownScriptIsAnFMException(): void
    {
        try {
            (new ScriptAccess($this->connection()))->executeScript('Blank_Keyword', 'No%20Such%20Script');
            self::fail('Expected an FMException');
        } catch (FMException $e) {
            self::assertSame(104, $e->getCode(), 'FileMaker 104: script is missing');
        }
    }

    public function testSetGlobalsReportsFileMakersErrorForAnUnknownField(): void
    {
        // The test database has no global fields, so FileMaker says the field is missing
        try {
            (new SetGlobals($this->connection()))->setGlobals(['Keyword::NoSuchGlobal' => 'x']);
            self::fail('Expected an FMException');
        } catch (FMException $e) {
            self::assertSame(102, $e->getCode(), 'FileMaker 102: field is missing');
        }
    }

    public function testUploadToAContainerField(): void
    {
        $keyword = (new Keyword())->setName('With a photo')->setCategory($this->marker);
        $this->em->persist($keyword);
        $this->em->flush();
        $record = $this->rawRecords('API_Keyword')[0];

        $file = tempnam(sys_get_temp_dir(), 'fmtest') . '.txt';
        file_put_contents($file, "container test\n");

        try {
            $container = new ContainerAccess($this->connection(), new ScriptAccess($this->connection()));
            $container->performContainerInsert('API_Keyword', (int) $record['recordId'], 'Photo', $file);
        } finally {
            @unlink($file);
        }

        $reloaded = $this->request()->performFMRequest('GET', sprintf('layouts/API_Keyword/records/%s', $record['recordId']), []);
        self::assertNotSame('', $reloaded[0]['fieldData']['Photo'], 'the container now holds a URL');
    }
}
