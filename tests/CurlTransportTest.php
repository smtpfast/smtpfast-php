<?php

declare(strict_types=1);

namespace SMTPfast\Tests;

use PHPUnit\Framework\TestCase;
use SMTPfast\Exception\ConnectionException;
use SMTPfast\Exception\TimeoutException;
use SMTPfast\Http\CurlTransport;

/**
 * The curl transport against PHP's built-in web server.
 */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        for ($try = 0; $try < 5 && self::$server === null; $try++) {
            $port = random_int(20000, 60000);
            $process = proc_open(
                [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/Fixtures/echo-server.php'],
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
            for ($wait = 0; is_resource($process) && $wait < 50; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    self::$server = $process;
                    self::$port = $port;
                    break;
                }
                usleep(100_000);
            }
            if (self::$server === null && is_resource($process)) {
                proc_terminate($process);
            }
        }
        if (self::$server === null) {
            self::fail('the built-in server did not start');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function echo(string $method, string $path, array $headers = [], ?string $body = null, float $timeout = 5.0): array
    {
        $response = (new CurlTransport())->send($method, 'http://127.0.0.1:' . self::$port . $path, $headers, $body, $timeout);
        $this->assertSame(201, $response->status);
        $this->assertSame('yes', $response->header('X-Echo'));

        return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSendsMethodHeadersAndBody(): void
    {
        $echo = $this->echo('PATCH', '/v1/contacts/ct_1?limit=2', ['Content-Type' => 'application/json', 'Idempotency-Key' => 'k1'], '{"a":1}');
        $this->assertSame('PATCH', $echo['method']);
        $this->assertSame('/v1/contacts/ct_1?limit=2', $echo['uri']);
        $this->assertSame('application/json', $echo['headers']['content-type']);
        $this->assertSame('k1', $echo['headers']['idempotency-key']);
        $this->assertSame('{"a":1}', $echo['body']);
        $this->assertArrayNotHasKey('expect', $echo['headers']);
    }

    public function testEmptyHeaderValueIsSent(): void
    {
        $echo = $this->echo('GET', '/v1/domains', ['X-Empty' => '', 'X-Full' => 'yes']);
        $this->assertSame('', $echo['headers']['x-empty'] ?? null);
        $this->assertSame('yes', $echo['headers']['x-full']);
    }

    public function testHead(): void
    {
        $response = (new CurlTransport())->send('HEAD', 'http://127.0.0.1:' . self::$port . '/v1/domains', [], null, 5.0);
        $this->assertSame(201, $response->status);
        $this->assertSame('', $response->body);
    }

    public function testPostWithoutBodySendsAnEmptyOne(): void
    {
        $echo = $this->echo('POST', '/v1/broadcasts/bc_1/cancel');
        $this->assertSame('POST', $echo['method']);
        $this->assertSame('0', $echo['headers']['content-length']);
        $this->assertArrayNotHasKey('content-type', $echo['headers']);
        $this->assertSame('', $echo['body']);
    }

    public function testDotSegmentsAreSentAsTheyAre(): void
    {
        $this->assertSame('/v1/emails/../domains', $this->echo('GET', '/v1/emails/../domains')['uri']);
    }

    public function testDoesNotFollowRedirects(): void
    {
        $response = (new CurlTransport())->send('GET', 'http://127.0.0.1:' . self::$port . '/redirect', [], null, 5.0);
        $this->assertSame(302, $response->status);
        $this->assertSame('/elsewhere', $response->header('location'));
    }

    public function testReusesTheTransportForSeveralCalls(): void
    {
        $transport = new CurlTransport();
        $base = 'http://127.0.0.1:' . self::$port;
        $this->assertSame(201, $transport->send('POST', "$base/a", ['Content-Type' => 'application/json'], '{}', 5.0)->status);
        $echo = json_decode($transport->send('GET', "$base/b", [], null, 5.0)->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['GET', '', '/b'], [$echo['method'], $echo['body'], $echo['uri']]);
        $this->assertArrayNotHasKey('content-type', $echo['headers']);
    }

    public function testTimeout(): void
    {
        $this->expectException(TimeoutException::class);
        (new CurlTransport())->send('GET', 'http://127.0.0.1:' . self::$port . '/slow', [], null, 0.3);
    }

    public function testConnectionRefused(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket); // the port is free again, so nothing answers on it
        try {
            (new CurlTransport())->send('GET', "http://$address/", [], null, 5.0);
            $this->fail('no exception');
        } catch (ConnectionException $e) {
            $this->assertNotInstanceOf(TimeoutException::class, $e);
        }
    }
}
