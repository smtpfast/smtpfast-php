<?php

declare(strict_types=1);

namespace SMTPfast;

use SMTPfast\Exception\SMTPfastException;
use SMTPfast\Http\CurlTransport;
use SMTPfast\Http\Transport;
use SMTPfast\Resources\ClientResources;

/**
 * The SMTPfast API client. Every operation is a method on one of its groups,
 * named after the path: $client->emails->send(), $client->inboxes->threads->emails->reply().
 *
 *     $client = new SMTPfast\Client(); // reads SMTPFAST_API_KEY
 *     $client->emails->send(['from' => 'you@yourdomain.com', 'to' => 'them@example.org', 'subject' => 'Hi', 'text' => 'Hello']);
 */
final class Client extends ClientResources
{
    /** The SDK version, sent in the User-Agent header. */
    public const VERSION = '0.1.0';

    /**
     * @param string|null $apiKey the default is the SMTPFAST_API_KEY environment variable
     * @param string|null $baseUrl the default is SMTPFAST_BASE_URL, then https://smtpfa.st/api
     * @param int $maxRetries retries after 429 (with Retry-After), 408, 5xx and dropped connections
     * @param float $timeout seconds, per attempt
     * @param array<string, string> $headers sent with every request
     * @param Transport|null $transport sends the HTTP requests; the default uses curl
     *
     * @throws SMTPfastException when there is no API key
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        int $maxRetries = 2,
        float $timeout = 60.0,
        array $headers = [],
        ?Transport $transport = null,
    ) {
        $key = trim($apiKey !== null && $apiKey !== '' ? $apiKey : (self::env('SMTPFAST_API_KEY') ?? ''));
        if ($key === '') {
            throw new SMTPfastException('No API key: pass apiKey or set the SMTPFAST_API_KEY environment variable.');
        }
        $base = $baseUrl !== null && $baseUrl !== '' ? $baseUrl : (self::env('SMTPFAST_BASE_URL') ?? Core::DEFAULT_BASE_URL);
        parent::__construct(new Core($key, rtrim($base, '/'), $maxRetries, $timeout, $headers, $transport ?? new CurlTransport()));
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
