<?php

declare(strict_types=1);

namespace SMTPfast;

use SMTPfast\Exception\ApiException;
use SMTPfast\Exception\ConnectionException;
use SMTPfast\Exception\SMTPfastException;
use SMTPfast\Http\Response;
use SMTPfast\Http\Transport;

/**
 * Sends requests: retries, idempotency keys, errors and pagination.
 *
 * @internal The generated resources call this; use the methods on Client.
 *
 * @phpstan-type RequestOptions array{timeout?: float|int, maxRetries?: int, headers?: array<string, string>}
 */
final class Core
{
    public const DEFAULT_BASE_URL = 'https://smtpfa.st/api';

    private const RETRY_STATUS = [408, 429, 500, 502, 503, 504];
    private const SAFE_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];
    private const MAX_RETRY_AFTER = 60.0;
    /** Query parameters a ...All method sets, or that cannot combine with walking forward by `after`. */
    private const PAGINATION_MANAGED = ['before', 'page', 'latest'];
    private const OPTION_KEYS = ['timeout', 'maxRetries', 'headers'];
    /** An RFC 7230 token: the characters a header name may have. */
    private const HEADER_NAME = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';

    /**
     * Waits between attempts. Tests replace it.
     *
     * @var (\Closure(float): void)|null
     */
    public static ?\Closure $sleep = null;

    /**
     * @param array<string, string> $headers sent with every request
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $maxRetries,
        private readonly float $timeout,
        private readonly array $headers,
        private readonly Transport $transport,
    ) {
        foreach ($headers as $name => $value) {
            self::checkHeader((string) $name, $value);
        }
    }

    /**
     * Send one request and return the decoded JSON answer: null for an empty
     * answer, the raw text when it is not JSON.
     *
     * @param array<string, string> $pathParams
     * @param array<string, mixed> $query
     * @param array<array-key, mixed>|null $body
     * @param string|null $bodyShape the operation id in BodyShapes, which says where the body has JSON objects
     * @param RequestOptions $options
     *
     * @throws SMTPfastException
     * @throws \InvalidArgumentException for a path parameter that is empty, "." or "..", or a header that cannot be sent
     */
    public function request(
        string $method,
        string $route,
        array $pathParams = [],
        array $query = [],
        ?array $body = null,
        ?string $bodyShape = null,
        bool $idempotent = false,
        ?string $idempotencyKey = null,
        array $options = [],
    ): mixed {
        // The array shape checks this statically; this is for callers without static analysis.
        $unknown = array_diff(array_keys($options), self::OPTION_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown request option "%s"; use timeout, maxRetries or headers.', implode('", "', $unknown)));
        }
        $maxRetries = $options['maxRetries'] ?? $this->maxRetries;
        $timeout = (float) ($options['timeout'] ?? $this->timeout);

        $headers = [];
        self::setHeader($headers, 'Authorization', 'Bearer ' . $this->apiKey);
        self::setHeader($headers, 'User-Agent', 'smtpfast-php/' . Client::VERSION);
        self::setHeader($headers, 'Accept', 'application/json');
        foreach ($this->headers as $name => $value) {
            self::setHeader($headers, (string) $name, $value);
        }
        foreach ($options['headers'] ?? [] as $name => $value) {
            self::setHeader($headers, (string) $name, $value);
        }
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            self::setHeader($headers, 'Idempotency-Key', $idempotencyKey);
        } elseif ($idempotent && self::getHeader($headers, 'Idempotency-Key') === null && $maxRetries > 0) {
            self::setHeader($headers, 'Idempotency-Key', self::uuid4());
        }
        // A POST or PATCH is retried only when its operation dedupes on the key.
        $retryable = in_array($method, self::SAFE_METHODS, true)
            || ($idempotent && (string) self::getHeader($headers, 'Idempotency-Key') !== '');

        $payload = null;
        if ($body !== null) {
            try {
                // json_encode writes an empty PHP array as [], so the spec says where {} is meant.
                $shape = $bodyShape !== null ? (BodyShapes::BODIES[$bodyShape] ?? []) : [];
                $payload = json_encode(self::objects($body, $shape), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $e) {
                throw new SMTPfastException('Could not encode the request body as JSON: ' . $e->getMessage(), 0, $e);
            }
            if (self::getHeader($headers, 'Content-Type') === null) {
                self::setHeader($headers, 'Content-Type', 'application/json');
            }
        }

        $url = $this->baseUrl . self::fill($route, $pathParams) . self::queryString($query);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $timeout);
            } catch (ConnectionException $e) {
                if (!$retryable || $attempt >= $maxRetries) {
                    throw $e;
                }
                self::wait(self::backoff($attempt));
                continue;
            }
            $wait = self::retryDelay($response, $attempt, $retryable);
            if ($wait === null || $attempt >= $maxRetries) {
                return self::result($response);
            }
            self::wait($wait);
        }
    }

    /**
     * Walk a cursor-paged list, yielding each item of `data`.
     *
     * @param array<string, string> $pathParams
     * @param array<string, mixed> $query
     * @param RequestOptions $options
     *
     * @return \Generator<int, mixed, mixed, void>
     */
    public function paginate(string $route, array $pathParams = [], array $query = [], array $options = []): \Generator
    {
        foreach (self::PAGINATION_MANAGED as $key) {
            unset($query[$key]);
        }
        while (true) {
            $page = $this->request('GET', $route, $pathParams, $query, options: $options);
            $items = is_array($page) && is_array($page['data'] ?? null) ? $page['data'] : [];
            foreach ($items as $item) {
                yield $item;
            }
            $last = $items === [] ? null : $items[array_key_last($items)];
            if (!is_array($page) || empty($page['has_more']) || !is_array($last) || !isset($last['id']) || $last['id'] === '') {
                return;
            }
            $query['after'] = $last['id'];
        }
    }

    /**
     * Seconds to wait before retrying this response, or null for no retry.
     */
    private static function retryDelay(Response $response, int $attempt, bool $retryable): ?float
    {
        if (!in_array($response->status, self::RETRY_STATUS, true)) {
            return null;
        }
        $after = self::parseRetryAfter($response->header('Retry-After'));
        if ($response->status === 429) {
            // A per-second limit sends Retry-After and rejects before any work is
            // done, so any method may retry. Without the header (an hourly or
            // daily limit), retrying soon cannot succeed.
            return $after !== null && $after <= self::MAX_RETRY_AFTER ? $after : null;
        }
        if (!$retryable) {
            return null;
        }
        if ($after !== null) {
            return $after <= self::MAX_RETRY_AFTER ? $after : null;
        }

        return self::backoff($attempt);
    }

    private static function parseRetryAfter(?string $header): ?float
    {
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }
        if (is_numeric($header)) {
            $seconds = (float) $header;

            return is_finite($seconds) ? max($seconds, 0.0) : null;
        }
        // RFC 850 has a two-digit year: one more than 50 years ahead is in the past century (RFC 9110).
        if (preg_match('/^[A-Za-z]+, (\d{2})-([A-Za-z]{3})-(\d{2}) (\d{2}:\d{2}:\d{2}) GMT$/D', $header, $m) === 1) {
            $year = intdiv((int) gmdate('Y'), 100) * 100 + (int) $m[3];
            if ($year > (int) gmdate('Y') + 50) {
                $year -= 100;
            }
            $header = sprintf('%s-%s-%d %s', $m[1], $m[2], $year, $m[4]);
        }
        // The other two HTTP-date formats are RFC 1123 and asctime.
        foreach (['D, d M Y H:i:s \G\M\T', 'd-M-Y H:i:s', 'D M j H:i:s Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $header, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return max((float) $date->getTimestamp() - microtime(true), 0.0);
            }
        }

        return null;
    }

    private static function backoff(int $attempt): float
    {
        return min(0.5 * 2 ** $attempt, 8.0) * (0.75 + mt_rand() / mt_getrandmax() * 0.5);
    }

    private static function wait(float $seconds): void
    {
        if (self::$sleep !== null) {
            (self::$sleep)($seconds);

            return;
        }
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    private static function result(Response $response): mixed
    {
        if ($response->status < 200 || $response->status >= 300) {
            throw ApiException::fromResponse($response);
        }
        if ($response->status === 204 || trim($response->body) === '') {
            return null;
        }
        try {
            return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $response->body;
        }
    }

    /**
     * Turn each empty PHP array that the shape marks as an object into {}.
     *
     * @param array<mixed> $shape o: an object, a: it can also be a list, p: its properties, v: the values of a map, i: the items of a list
     */
    private static function objects(mixed $value, array $shape): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return isset($shape['o']) ? new \stdClass() : [];
        }
        $items = $shape['i'] ?? null;
        if (array_is_list($value) && is_array($items)) {
            return array_map(fn (mixed $item): mixed => self::objects($item, $items), $value);
        }
        $properties = is_array($shape['p'] ?? null) ? $shape['p'] : [];
        $values = is_array($shape['v'] ?? null) ? $shape['v'] : null;
        foreach ($value as $key => $item) {
            $child = is_array($properties[$key] ?? null) ? $properties[$key] : $values;
            if ($child !== null) {
                $value[$key] = self::objects($item, $child);
            }
        }
        // A map with keys 0, 1, ... is still an object, such as template variables named 0 and 1.
        if (isset($shape['o']) && !isset($shape['a']) && array_is_list($value)) {
            return (object) $value;
        }

        return $value;
    }

    /**
     * @param array<string, string> $values
     */
    private static function fill(string $route, array $values): string
    {
        foreach ($values as $name => $value) {
            // rawurlencode keeps these as they are, and as a path segment they
            // would point the request at another endpoint.
            if ($value === '' || $value === '.' || $value === '..') {
                throw new \InvalidArgumentException(sprintf('Path parameter "%s" must not be empty, "." or "..".', $name));
            }
            $route = str_replace('{' . $name . '}', rawurlencode($value), $route);
        }

        return $route;
    }

    /**
     * Null values are left out, booleans are true or false, and a list repeats the name.
     *
     * @param array<string, mixed> $query
     */
    private static function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item === null) {
                    continue;
                }
                if (is_bool($item)) {
                    $item = $item ? 'true' : 'false';
                } elseif (!is_scalar($item) && !$item instanceof \Stringable) {
                    throw new \InvalidArgumentException(sprintf('Query parameter "%s" must be a scalar or a list of scalars.', $name));
                }
                $pairs[] = rawurlencode($name) . '=' . rawurlencode((string) $item);
            }
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /**
     * Set a header, replacing any with the same name in another letter case.
     *
     * @param array<string, string> $headers
     */
    private static function setHeader(array &$headers, string $name, string $value): void
    {
        self::checkHeader($name, $value);
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }
        $headers[$name] = $value;
    }

    /**
     * Refuse a header that would break the request or add another header.
     * The message names the header but never repeats its value.
     */
    private static function checkHeader(string $name, string $value): void
    {
        if (preg_match(self::HEADER_NAME, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid header name "%s".', addcslashes($name, "\0..\37\177")));
        }
        // RFC 9110 field values: visible characters, spaces and tabs, but no other control characters.
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('The value of header %s contains a line break or another control character.', $name));
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private static function getHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $existing => $value) {
            if (strcasecmp($existing, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
