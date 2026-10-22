<?php

declare(strict_types=1);

namespace SMTPfast\Tests;

use SMTPfast\Client;
use SMTPfast\Core;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var list<float> */
    protected array $waits = [];

    protected function setUp(): void
    {
        $this->waits = [];
        Core::$sleep = function (float $seconds): void {
            $this->waits[] = $seconds;
        };
    }

    protected function tearDown(): void
    {
        Core::$sleep = null;
    }

    /**
     * @param list<\SMTPfast\Http\Response|\Throwable|\Closure> $replies
     * @param array<string, string> $headers
     *
     * @return array{Client, FakeTransport}
     */
    protected function client(array $replies = [], int $maxRetries = 2, array $headers = []): array
    {
        $transport = new FakeTransport($replies);

        return [new Client('sf_test_key', maxRetries: $maxRetries, headers: $headers, transport: $transport), $transport];
    }
}
