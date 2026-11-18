<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the HTTP client the driver uses when it isn't given one, from the connection's `driverOptions`:
 *
 *  - `http_client_options`  default request options for the client (verify_peer, proxy, timeout, ...)
 *  - `max_tls_version`      never negotiate a newer TLS version than this (see MaxTlsHttpClient). Defaults to "1.2",
 *                           as FileMaker Server can't be relied on to cope with newer handshakes, provided ext-curl
 *                           is there to do it. Set it to null (or "none") to use Symfony's client as it comes.
 */
final class HttpClientFactory
{
    public static function create(array $connectionParams): HttpClientInterface
    {
        $driverOptions = $connectionParams['driverOptions'] ?? [];
        $clientOptions = $driverOptions['http_client_options'] ?? [];

        $maxTls = array_key_exists('max_tls_version', $driverOptions)
            ? $driverOptions['max_tls_version']
            : (extension_loaded('curl') ? '1.2' : null);

        if (!empty($maxTls) && 'none' !== strtolower((string) $maxTls)) {
            return new MaxTlsHttpClient((string) $maxTls, $clientOptions);
        }

        return HttpClient::create($clientOptions);
    }
}
