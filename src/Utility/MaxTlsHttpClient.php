<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Utility;

use CurlHandle;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * An HTTP client which never negotiates a TLS version above the one given.
 *
 * Symfony's own clients can only set a *minimum* TLS version, but some servers can't cope with the ClientHello of a
 * client which also offers newer versions (OpenSSL 4 in particular: the server answers with a "decode error" alert).
 * libcurl can cap the version, so this performs the requests with ext-curl and uses Symfony's MockHttpClient purely
 * for what it is good at here: normalising request options (headers, auth, body) and building ordinary
 * ResponseInterface objects. Enable it with the `max_tls_version` driver option.
 *
 * Supported request options: headers, auth_basic, auth_bearer, body, max_redirects, timeout, max_duration, proxy,
 * verify_peer, verify_host, cafile, capath. Responses are buffered, which is all the Data API needs.
 */
final class MaxTlsHttpClient implements HttpClientInterface
{
    private const MAXIMUMS = [
        '1.0' => 4 << 16,
        '1.1' => 5 << 16,
        '1.2' => 6 << 16,
        '1.3' => 7 << 16,
    ];

    private const TLS_1_2 = 6;

    private MockHttpClient $inner;

    private ?CurlHandle $handle = null;

    /**
     * @param string $maxVersion    "1.0", "1.1", "1.2" or "1.3"
     * @param array  $defaultOptions Default request options, as for HttpClient::create()
     */
    public function __construct(private readonly string $maxVersion = '1.2', array $defaultOptions = [])
    {
        if (!isset(self::MAXIMUMS[$maxVersion])) {
            throw new InvalidArgumentException(sprintf('max_tls_version must be one of "%s", got "%s".', implode('", "', array_keys(self::MAXIMUMS)), $maxVersion));
        }
        if (!extension_loaded('curl')) {
            throw new LogicException('Capping the TLS version needs the curl extension.');
        }

        $this->inner = new MockHttpClient(fn (string $method, string $url, array $options) => $this->perform($method, $url, $options));
        if ($defaultOptions) {
            $this->inner = $this->inner->withOptions($defaultOptions);
        }
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }

    private function perform(string $method, string $url, array $options): MockResponse
    {
        if (null === $this->handle) {
            $this->handle = curl_init();
        } else {
            // Forgets the options but keeps the connection cache, so requests still reuse connections
            curl_reset($this->handle);
        }
        $ch = $this->handle;

        $responseHeaders = [];
        $maxRedirects = (int) ($options['max_redirects'] ?? 20);
        $idleTimeout = (float) ($options['timeout'] ?? ini_get('default_socket_timeout'));

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->headerLines($options['headers'] ?? []),
            CURLOPT_ENCODING => '',
            CURLOPT_SSLVERSION => ($this->maxVersion >= '1.2' ? self::TLS_1_2 : 0) | self::MAXIMUMS[$this->maxVersion],
            CURLOPT_SSL_VERIFYPEER => (bool) ($options['verify_peer'] ?? true),
            CURLOPT_SSL_VERIFYHOST => ($options['verify_host'] ?? true) ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => $maxRedirects > 0,
            CURLOPT_MAXREDIRS => max($maxRedirects, 0),
            CURLOPT_CONNECTTIMEOUT => $idleTimeout > 0 ? (int) ceil($idleTimeout) : 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trimmed = rtrim($line, "\r\n");
                if ('' !== $trimmed) {
                    $responseHeaders[] = $trimmed;
                }

                return strlen($line);
            },
        ];

        if ($idleTimeout > 0) {
            // Symfony's "timeout" is how long the transfer may stall, not a limit on the whole request
            $curlOptions[CURLOPT_LOW_SPEED_LIMIT] = 1;
            $curlOptions[CURLOPT_LOW_SPEED_TIME] = (int) ceil($idleTimeout);
        }
        if (($options['max_duration'] ?? 0) > 0) {
            $curlOptions[CURLOPT_TIMEOUT_MS] = (int) ceil(1000 * $options['max_duration']);
        }
        foreach (['cafile' => CURLOPT_CAINFO, 'capath' => CURLOPT_CAPATH, 'proxy' => CURLOPT_PROXY] as $option => $curlOption) {
            if (!empty($options[$option])) {
                $curlOptions[$curlOption] = $options[$option];
            }
        }

        $body = $this->readBody($options['body'] ?? '');
        if ('HEAD' === $method) {
            $curlOptions[CURLOPT_NOBODY] = true;
        } elseif ('' !== $body || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            // An empty body still has to be announced (Content-Length: 0), or servers answer "unsupported media type"
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $curlOptions);
        $content = curl_exec($ch);

        if (false === $content) {
            throw new TransportException(sprintf('%s for "%s".', curl_error($ch), $url), curl_errno($ch));
        }

        return new MockResponse($content, [
            'http_code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'response_headers' => $responseHeaders,
        ]);
    }

    /**
     * Drop the headers curl works out for itself (the encoding negotiated by CURLOPT_ENCODING and the length of the body)
     *
     * @param list<string> $headers "Name: value" lines
     *
     * @return list<string>
     */
    private function headerLines(array $headers): array
    {
        return array_values(array_filter(
            $headers,
            static fn (string $line): bool => !preg_match('/^(content-length|accept-encoding|host)\s*:/i', $line)
        ));
    }

    /**
     * @param string|\Closure|resource|iterable $body
     */
    private function readBody(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }
        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }
        if ($body instanceof \Closure) {
            $content = '';
            while ('' !== $chunk = (string) $body(16372)) {
                $content .= $chunk;
            }

            return $content;
        }

        throw new LogicException(sprintf('Unsupported request body of type "%s".', get_debug_type($body)));
    }
}
