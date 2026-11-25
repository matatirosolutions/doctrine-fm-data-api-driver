<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the HTTP client the driver uses when it isn't given one, from the connection's `driverOptions`:
 *
 *  - `http_client_options`  default request options for the client (verify_peer, proxy, timeout, ...). Unless you set
 *                           `timeout` it is 300 seconds: Symfony's own default is PHP's default_socket_timeout (normally 60),
 *                           which is too short for the slowest FileMaker finds. (Guzzle, which 0.x used, never timed out.)
 *  - `max_tls_version`      e.g. "1.2": never negotiate a newer TLS version (see MaxTlsHttpClient)
 */
final class HttpClientFactory
{
    /** Seconds a request may go without any data arriving before it's abandoned */
    public const DEFAULT_TIMEOUT = 300.0;

    public static function create(array $connectionParams): HttpClientInterface
    {
        $driverOptions = $connectionParams['driverOptions'] ?? [];
        $clientOptions = ($driverOptions['http_client_options'] ?? []) + ['timeout' => self::DEFAULT_TIMEOUT];

        if (!empty($driverOptions['max_tls_version'])) {
            return new MaxTlsHttpClient((string) $driverOptions['max_tls_version'], $clientOptions);
        }

        return HttpClient::create($clientOptions);
    }
}
