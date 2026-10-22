<?php

declare(strict_types=1);

namespace SMTPfast\Http;

use SMTPfast\Exception\ConnectionException;
use SMTPfast\Exception\TimeoutException;

/**
 * The default transport, on the curl extension. It keeps one handle, so
 * calls from the same client reuse the connection.
 */
final class CurlTransport implements Transport
{
    private ?\CurlHandle $handle = null;

    public function __construct()
    {
        if (!extension_loaded('curl')) {
            throw new \LogicException('The SMTPfast SDK needs the curl extension, or a Transport of your own.');
        }
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $handle = $this->handle ??= curl_init();
        curl_reset($handle);

        $lines = [];
        foreach ($headers as $name => $value) {
            // "Name:" with no value tells curl to drop the header; "Name;" sends it empty.
            $lines[] = $value === '' ? $name . ';' : $name . ': ' . $value;
        }
        $lines[] = 'Expect:';
        if ($body === null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            // Send Content-Length: 0, without the form content type curl would add.
            $body = '';
            $lines[] = 'Content-Type:';
        }

        $responseHeaders = [];
        $milliseconds = max(1, (int) ceil($timeout * 1000));
        /** @var array<int, mixed> $settings */
        $settings = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            // Send the path as built, never resolving dot-segments in it.
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_TIMEOUT_MS => $milliseconds,
            CURLOPT_CONNECTTIMEOUT_MS => $milliseconds,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new answer, such as the one after 100 Continue
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ];
        curl_setopt_array($handle, $settings);
        if ($method === 'HEAD') {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        } elseif ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($handle);
        if (!is_string($result)) {
            $error = curl_error($handle);
            if (curl_errno($handle) === CURLE_OPERATION_TIMEDOUT) {
                throw new TimeoutException($timeout, $error);
            }
            throw new ConnectionException('Could not connect to the SMTPfast API: ' . $error);
        }

        return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $result);
    }
}
