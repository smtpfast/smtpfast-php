<?php

declare(strict_types=1);

namespace SMTPfast\Http;

use SMTPfast\Exception\ConnectionException;

/**
 * Sends one HTTP request. The client handles retries, so an implementation
 * makes exactly one attempt and must not follow redirects.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @param float $timeout seconds for the whole attempt
     *
     * @throws ConnectionException when no answer arrived; a TimeoutException when time ran out
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
