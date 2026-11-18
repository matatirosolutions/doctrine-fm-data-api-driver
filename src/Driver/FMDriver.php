<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Driver;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\ServerVersionProvider;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use MSDev\DoctrineFMDataAPIDriver\Platform\FMPlatform;

/**
 * Doctrine DBAL driver for the FileMaker Data API.
 *
 * Configure it with `driver_class: MSDev\DoctrineFMDataAPIDriver\Driver\FMDriver`. To supply your own
 * Symfony HttpClient (proxy, timeouts, ...) pass it as the `http_client` driver option.
 *
 * @author Steve Winter <steve@msdev.co.uk>
 */
class FMDriver implements Driver
{
    public function connect(#[SensitiveParameter] array $params): FMConnection
    {
        $client = $params['driverOptions']['http_client'] ?? null;

        return new FMConnection($params, $client instanceof HttpClientInterface ? $client : null);
    }

    public function getDatabasePlatform(ServerVersionProvider $versionProvider): FMPlatform
    {
        return new FMPlatform();
    }

    public function getExceptionConverter(): ExceptionConverterInterface
    {
        return new ExceptionConverter();
    }
}
