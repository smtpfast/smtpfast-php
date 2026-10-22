<?php

declare(strict_types=1);

namespace SMTPfast\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SMTPfast\Client;
use SMTPfast\Exception\PermissionDeniedException;

/**
 * Read-only calls against the real API, each answer checked against the
 * response schema the spec documents: types and required fields. Runs only
 * with SMTPFAST_API_KEY set; a key with email:read and domain:read is enough.
 */
final class LiveTest extends \PHPUnit\Framework\TestCase
{
    /** @var array<string, mixed> */
    private static array $spec = [];

    protected function setUp(): void
    {
        if ((string) getenv('SMTPFAST_API_KEY') === '') {
            $this->markTestSkipped('SMTPFAST_API_KEY is not set');
        }
        self::$spec = self::$spec ?: json_decode((string) file_get_contents(__DIR__ . '/../spec/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAccountMe(): void
    {
        $this->check('getCapabilities', (new Client())->account->me());
    }

    public function testEmails(): void
    {
        $client = new Client();
        $page = $client->emails->list(limit: 3);
        $this->check('listEmails', $page);
        if ($page['data'] !== []) {
            $this->check('getEmail', $client->emails->get($page['data'][0]['id']));
        }
    }

    public function testDomains(): void
    {
        $client = new Client();
        $domains = $client->domains->list();
        $this->check('listDomains', $domains);
        if ($domains['data'] !== []) {
            $this->check('getDomain', $client->domains->get($domains['data'][0]['id']));
        }
    }

    /**
     * @return iterable<string, array{string, \Closure(Client): mixed}>
     */
    public static function optional(): iterable
    {
        yield 'listLogs' => ['listLogs', fn (Client $c) => $c->logs->list(limit: 3)];
        yield 'getEmailMetrics' => ['getEmailMetrics', fn (Client $c) => $c->emails->getMetrics()];
        yield 'listClickedLinks' => ['listClickedLinks', fn (Client $c) => $c->emails->listClickedLinks()];
        yield 'listReceivedEmails' => ['listReceivedEmails', fn (Client $c) => $c->emails->receiving->list(limit: 3)];
        yield 'getUsage' => ['getUsage', fn (Client $c) => $c->account->usage()];
        yield 'getAnalytics' => ['getAnalytics', fn (Client $c) => $c->analytics->get()];
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('optional')]
    public function testOptional(string $operationId, \Closure $call): void
    {
        try {
            $body = $call(new Client());
        } catch (PermissionDeniedException) {
            $this->markTestSkipped("$operationId: this key lacks the scope");
        }
        $this->check($operationId, $body);
    }

    private function check(string $operationId, mixed $body): void
    {
        foreach (self::$spec['paths'] as $item) {
            foreach ($item as $op) {
                if (is_array($op) && ($op['operationId'] ?? null) === $operationId) {
                    foreach ($op['responses'] as $code => $response) {
                        if (str_starts_with((string) $code, '2')) {
                            $errors = [];
                            $this->validate($response['content']['application/json']['schema'], $body, '', $errors);
                            $this->assertSame([], array_slice($errors, 0, 5), "$operationId does not match the spec");

                            return;
                        }
                    }
                }
            }
        }
        $this->fail("no operation $operationId");
    }

    /**
     * A small subset of JSON Schema: $ref, allOf, anyOf/oneOf, enum, type,
     * required, properties and items. Enough to catch an answer that drifted
     * from the spec.
     *
     * @param array<string, mixed> $schema
     * @param list<string> $errors
     */
    private function validate(array $schema, mixed $value, string $at, array &$errors): void
    {
        while (isset($schema['$ref'])) {
            $schema = self::$spec['components']['schemas'][basename($schema['$ref'])];
        }
        foreach ($schema['allOf'] ?? [] as $part) {
            $this->validate($part, $value, $at, $errors);
        }
        foreach (['anyOf', 'oneOf'] as $key) {
            if (isset($schema[$key])) {
                $matched = false;
                foreach ($schema[$key] as $option) {
                    $optionErrors = [];
                    $this->validate($option, $value, $at, $optionErrors);
                    $matched = $matched || $optionErrors === [];
                }
                if (!$matched) {
                    $errors[] = "$at: matches none of $key";
                }
            }
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "$at: " . json_encode($value) . ' is not one of ' . json_encode($schema['enum']);
        }
        $types = (array) ($schema['type'] ?? []);
        if ($types !== [] && !$this->hasType($value, $types)) {
            $errors[] = "$at: " . get_debug_type($value) . ' is not ' . implode('|', $types);

            return;
        }
        if (is_array($value) && (isset($schema['properties']) || isset($schema['required']))) {
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $value)) {
                    $errors[] = "$at: $key is missing";
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $property) {
                if (array_key_exists($key, $value)) {
                    $this->validate($property, $value[$key], "$at.$key", $errors);
                }
            }
        }
        if (is_array($value) && isset($schema['items']) && array_is_list($value)) {
            foreach ($value as $i => $item) {
                $this->validate($schema['items'], $item, "{$at}[{$i}]", $errors);
            }
        }
    }

    /**
     * @param list<string> $types
     */
    private function hasType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'null' => $value === null,
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                // A JSON object decodes to a PHP array, and {} to [].
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                default => true,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
