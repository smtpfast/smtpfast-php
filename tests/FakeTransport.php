<?php

declare(strict_types=1);

namespace SMTPfast\Tests;

use SMTPfast\Http\Response;
use SMTPfast\Http\Transport;

/**
 * Records every request and answers from a list: a Response, an exception to
 * throw, or a closure that gets the request. After the list, it answers 200 {"ok":true}.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    public array $calls = [];

    /**
     * @param list<Response|\Throwable|\Closure> $replies
     */
    public function __construct(public array $replies = [])
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $call = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        $this->calls[] = $call;
        $reply = array_shift($this->replies) ?? self::json(['ok' => true]);
        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return $reply instanceof \Closure ? $reply($call) : $reply;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(mixed $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function header(int $i, string $name): ?string
    {
        foreach ($this->calls[$i]['headers'] as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<string> every value sent for the header, in any letter case
     */
    public function headerValues(int $i, string $name): array
    {
        $values = [];
        foreach ($this->calls[$i]['headers'] as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function body(int $i = 0): mixed
    {
        $body = $this->calls[$i]['body'];

        return $body === null ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function path(int $i = 0): string
    {
        return (string) parse_url($this->calls[$i]['url'], PHP_URL_PATH);
    }

    public function query(int $i = 0): string
    {
        return (string) parse_url($this->calls[$i]['url'], PHP_URL_QUERY);
    }
}
