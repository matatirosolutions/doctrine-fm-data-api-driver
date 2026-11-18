<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the HTTP client the driver uses when it isn't given one, from the connection's `driverOptions`:
 *
 *  - `http_client_options`  default request options for the client (verify_peer, proxy, timeout, ...)
 *  - `max_tls_version`      e.g. "1.2": never negotiate a newer TLS version (see MaxTlsHttpClient)
 */
final class HttpClientFactory
{
    public static function create(array $connectionParams): HttpClientInterface
    {
        $driverOptions = $connectionParams['driverOptions'] ?? [];
        $clientOptions = $driverOptions['http_client_options'] ?? [];

        if (!empty($driverOptions['max_tls_version'])) {
            return new MaxTlsHttpClient((string) $driverOptions['max_tls_version'], $clientOptions);
        }

        return HttpClient::create($clientOptions);
    }
}
