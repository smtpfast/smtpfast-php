<?php

declare(strict_types=1);

namespace SMTPfast\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every operation in the spec is reachable as $client-><group>-><method>()
 * and sends the method and path the spec documents.
 */
final class CoverageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function operations(): iterable
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/../spec/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $op) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    yield implode('.', [...$op['x-sdk-group'], $op['x-sdk-method']]) => [$path, $method, $op];
                }
            }
        }
    }

    public function testTheSpecHasOperations(): void
    {
        $this->assertGreaterThan(100, iterator_count(self::operations()));
    }

    /**
     * @param array<string, mixed> $op
     */
    #[DataProvider('operations')]
    public function testOperation(string $path, string $method, array $op): void
    {
        [$client, $t] = $this->client();
        $target = $client;
        foreach ($op['x-sdk-group'] as $group) {
            $this->assertTrue(property_exists($target, $group), "group $group is missing");
            $target = $target->{$group};
        }
        $this->assertTrue(method_exists($target, $op['x-sdk-method']), "method {$op['x-sdk-method']} is missing");

        preg_match_all('/\{(\w+)\}/', $path, $matches);
        $args = [];
        $expected = $path;
        foreach ($matches[1] as $i => $name) {
            $args[] = "p$i";
            $expected = str_replace('{' . $name . '}', "p$i", $expected);
        }
        if (isset($op['requestBody'])) {
            $args[] = [];
        }
        foreach ($op['parameters'] ?? [] as $param) {
            if ($param['in'] === 'query' && !empty($param['required'])) {
                $args[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $param['name']))))] = 'x';
            }
        }
        $target->{$op['x-sdk-method']}(...$args);

        $this->assertCount(1, $t->calls);
        $this->assertSame(strtoupper($method), $t->calls[0]['method']);
        $this->assertSame('/api' . $expected, $t->path());
    }
}
