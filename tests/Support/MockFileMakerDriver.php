<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Tests\Support;

use MSDev\DoctrineFMDataAPIDriver\Driver\FMConnection;
use MSDev\DoctrineFMDataAPIDriver\Driver\FMDriver;

/**
 * DBAL instantiates driver classes itself (via the `driverClass` param), so the
 * mock server a test wants to talk to is handed over through a static.
 */
final class MockFileMakerDriver extends FMDriver
{
    public static ?MockFileMaker $mock = null;

    public function connect(#[\SensitiveParameter] array $params): FMConnection
    {
        return (self::$mock ?? throw new \LogicException('No MockFileMaker registered'))->connection($params);
    }
}
