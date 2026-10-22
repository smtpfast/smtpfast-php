<?php

declare(strict_types=1);

namespace SMTPfast\Tests;

use SMTPfast\Client;
use SMTPfast\Exception\ApiException;
use SMTPfast\Exception\AuthenticationException;
use SMTPfast\Exception\ConnectionException;
use SMTPfast\Exception\InternalServerException;
use SMTPfast\Exception\NotFoundException;
use SMTPfast\Exception\RateLimitException;
use SMTPfast\Exception\SMTPfastException;
use SMTPfast\Exception\TimeoutException;
use SMTPfast\Exception\UnprocessableEntityException;
use SMTPfast\Exception\WebhookVerificationException;
use SMTPfast\Http\Response;
use SMTPfast\Webhooks;

final class ClientTest extends TestCase
{
    private const NO_WAIT = ['Retry-After' => '0'];

    public function testSendEmail(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['id' => 'em_1', 'object' => 'email'])]);
        $sent = $client->emails->send(['from' => 'a@yourapp.dev', 'to' => ['b@inbox.dev'], 'subject' => 'Hi', 'text' => 'Hello']);
        $this->assertSame(['id' => 'em_1', 'object' => 'email'], $sent);
        $this->assertSame('POST', $t->calls[0]['method']);
        $this->assertSame('https://smtpfa.st/api/v1/emails', $t->calls[0]['url']);
        $this->assertSame('Bearer sf_test_key', $t->header(0, 'Authorization'));
        $this->assertStringStartsWith('smtpfast-php/', (string) $t->header(0, 'User-Agent'));
        $this->assertSame('application/json', $t->header(0, 'Content-Type'));
        $this->assertSame('{"from":"a@yourapp.dev","to":["b@inbox.dev"],"subject":"Hi","text":"Hello"}', $t->calls[0]['body']);
    }

    public function testPathParamsAreEncoded(): void
    {
        [$client, $t] = $this->client();
        $client->inboxes->threads->emails->reply('in 1', 'th_2', 'em/3', ['text' => 'Thanks']);
        $this->assertSame('/api/v1/inboxes/in%201/threads/th_2/emails/em%2F3/reply', $t->path());
    }

    public function testQueryParamsSkipNullAndFormatBools(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['data' => [], 'has_more' => false])]);
        $client->contacts->list(limit: 5, disposable: false);
        parse_str($t->query(), $query);
        ksort($query);
        $this->assertSame(['disposable' => 'false', 'limit' => '5'], $query);
    }

    public function testListQueryParamRepeatsTheName(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['data' => [], 'has_more' => false])]);
        $client->inboxes->threads->list('in_1', label: ['a', 'b c']);
        $this->assertSame('label=a&label=b%20c', $t->query());
    }

    public function testApiKeyAndBaseUrlFromEnvironment(): void
    {
        $this->withEnv(['SMTPFAST_API_KEY' => 'sf_env', 'SMTPFAST_BASE_URL' => null], function (): void {
            $t = new FakeTransport([FakeTransport::json([])]);
            $client = new Client(baseUrl: 'http://localhost:3000/api/', transport: $t);
            $client->domains->list();
            $this->assertSame('http://localhost:3000/api/v1/domains', $t->calls[0]['url']);
            $this->assertSame('Bearer sf_env', $t->header(0, 'Authorization'));
        });
    }

    public function testNoApiKey(): void
    {
        $this->withEnv(['SMTPFAST_API_KEY' => null], function (): void {
            $this->expectException(SMTPfastException::class);
            new Client(transport: new FakeTransport());
        });
    }

    public function testTypedErrors(): void
    {
        [$client] = $this->client([FakeTransport::json(['error' => 'Invalid API key'], 401)]);
        try {
            $client->domains->list();
            $this->fail('no exception');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('401: Invalid API key', $e->getMessage());
            $this->assertSame(['error' => 'Invalid API key'], $e->body);
            $this->assertSame('application/json', $e->headers['content-type']);
        }
    }

    public function testErrorKeepsExtraFields(): void
    {
        $body = ['error' => 'Missing template variables', 'missing_variables' => ['NAME']];
        [$client] = $this->client([FakeTransport::json($body, 422)]);
        try {
            $client->emails->send(['from' => 'a@yourapp.dev', 'to' => 'b@inbox.dev', 'template' => ['id' => 't']]);
            $this->fail('no exception');
        } catch (UnprocessableEntityException $e) {
            $this->assertSame($body, $e->body);
        }
    }

    public function testErrorThatIsNotJson(): void
    {
        [$client] = $this->client([new Response(502, [], ' Bad gateway ')], maxRetries: 0);
        try {
            $client->domains->list();
            $this->fail('no exception');
        } catch (InternalServerException $e) {
            $this->assertSame('502: Bad gateway', $e->getMessage());
            $this->assertSame(' Bad gateway ', $e->body);
        }
    }

    public function testRetriesGetAfter503(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['error' => 'busy'], 503, self::NO_WAIT), FakeTransport::json([])]);
        $this->assertSame([], $client->domains->list());
        $this->assertCount(2, $t->calls);
    }

    public function testSendRetryReusesIdempotencyKey(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['error' => 'oops'], 500, self::NO_WAIT), FakeTransport::json(['id' => 'em_1'])]);
        $client->emails->send(['from' => 'a@yourapp.dev', 'to' => 'b@inbox.dev', 'subject' => 's', 'text' => 't']);
        $this->assertCount(2, $t->calls);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $t->header(0, 'Idempotency-Key'));
        $this->assertSame($t->header(0, 'Idempotency-Key'), $t->header(1, 'Idempotency-Key'));
    }

    public function testEachSendGetsItsOwnKey(): void
    {
        [$client, $t] = $this->client();
        $client->emails->send(['to' => 'b@inbox.dev']);
        $client->emails->send(['to' => 'b@inbox.dev']);
        $this->assertNotSame($t->header(0, 'Idempotency-Key'), $t->header(1, 'Idempotency-Key'));
    }

    public function testCallersIdempotencyKey(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['id' => 'em_1'])]);
        $client->emails->send(['from' => 'a@yourapp.dev', 'to' => 'b@inbox.dev', 'subject' => 's', 'text' => 't'], idempotencyKey: 'order-42');
        $this->assertSame('order-42', $t->header(0, 'Idempotency-Key'));
    }

    public function testReplyAndForwardAreIdempotent(): void
    {
        [$client, $t] = $this->client([
            FakeTransport::json([], 500, self::NO_WAIT), FakeTransport::json(['id' => 'em_2']),
            FakeTransport::json([], 503, self::NO_WAIT), FakeTransport::json(['id' => 'em_3']),
        ]);
        $client->inboxes->threads->emails->reply('in_1', 'th_1', 'em_1', ['text' => 'Thanks']);
        $client->inboxes->threads->emails->forward('in_1', 'th_1', 'em_1', ['to' => 'c@inbox.dev']);
        $this->assertCount(4, $t->calls);
        $this->assertSame($t->header(0, 'Idempotency-Key'), $t->header(1, 'Idempotency-Key'));
        $this->assertSame($t->header(2, 'Idempotency-Key'), $t->header(3, 'Idempotency-Key'));
    }

    public function testNoKeyWithoutRetries(): void
    {
        [$client, $t] = $this->client(maxRetries: 0);
        $client->emails->send(['to' => 'b@inbox.dev']);
        $this->assertNull($t->header(0, 'Idempotency-Key'));
    }

    public function testPostWithoutKeyIsNotRetriedAfter500(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['error' => 'oops'], 500, self::NO_WAIT)]);
        try {
            $client->contacts->create(['email' => 'b@inbox.dev']);
            $this->fail('no exception');
        } catch (InternalServerException) {
            $this->assertCount(1, $t->calls);
            $this->assertNull($t->header(0, 'Idempotency-Key'));
        }
    }

    public function testPatchIsNotRetriedAfterADroppedConnection(): void
    {
        [$client, $t] = $this->client([new ConnectionException('reset')]);
        $this->expectException(ConnectionException::class);
        try {
            $client->contacts->update('ct_1', ['first_name' => 'Ann']);
        } finally {
            $this->assertCount(1, $t->calls);
        }
    }

    public function testPutAndDeleteAreRetriedAfter5xx(): void
    {
        [$client, $t] = $this->client([
            FakeTransport::json([], 502, self::NO_WAIT), FakeTransport::json(['id' => 'wh_1']),
            FakeTransport::json([], 500, self::NO_WAIT), FakeTransport::json(['deleted' => true]),
        ]);
        $client->webhooks->replace('wh_1', ['url' => 'https://yourapp.dev/hook', 'events' => ['email.sent']]);
        $client->webhooks->delete('wh_1');
        $this->assertSame(['PUT', 'PUT', 'DELETE', 'DELETE'], array_column($t->calls, 'method'));
    }

    public function testPerSecond429IsRetriedForAnyMethod(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['error' => 'Rate limit exceeded'], 429, self::NO_WAIT), FakeTransport::json(['id' => 'ct_1'])]);
        $this->assertSame(['id' => 'ct_1'], $client->contacts->create(['email' => 'b@inbox.dev']));
        $this->assertCount(2, $t->calls);
    }

    public function test429WaitsForRetryAfter(): void
    {
        [$client] = $this->client([FakeTransport::json([], 429, ['Retry-After' => '1.5']), FakeTransport::json([])]);
        $client->domains->list();
        $this->assertSame([1.5], $this->waits);
    }

    public function test429WithoutRetryAfterIsNotRetried(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['error' => 'Hourly sending limit reached'], 429)]);
        try {
            $client->domains->list();
            $this->fail('no exception');
        } catch (RateLimitException) {
            $this->assertCount(1, $t->calls);
        }
    }

    public function testStopsAfterMaxRetries(): void
    {
        [$client, $t] = $this->client(array_fill(0, 5, FakeTransport::json(['error' => 'busy'], 503, self::NO_WAIT)), maxRetries: 2);
        try {
            $client->domains->list();
            $this->fail('no exception');
        } catch (InternalServerException) {
            $this->assertCount(3, $t->calls);
        }
    }

    public function testDroppedConnection(): void
    {
        [$client, $t] = $this->client([new ConnectionException('reset'), new ConnectionException('reset')], maxRetries: 1);
        try {
            $client->domains->list();
            $this->fail('no exception');
        } catch (ConnectionException) {
            $this->assertCount(2, $t->calls);
            $this->assertCount(1, $this->waits);
        }
    }

    public function testTimeout(): void
    {
        [$client] = $this->client([new TimeoutException(60.0)], maxRetries: 0);
        $this->expectException(TimeoutException::class);
        $client->domains->list();
    }

    public function testRequestOptions(): void
    {
        [$client, $t] = $this->client([FakeTransport::json([], 503, self::NO_WAIT), FakeTransport::json([])]);
        try {
            $client->domains->list(options: ['timeout' => 5, 'maxRetries' => 0, 'headers' => ['X-Trace' => 'abc']]);
            $this->fail('no exception');
        } catch (InternalServerException) {
            $this->assertCount(1, $t->calls);
            $this->assertSame(5.0, $t->calls[0]['timeout']);
            $this->assertSame('abc', $t->header(0, 'X-Trace'));
        }
        $client->domains->list();
        $this->assertSame(60.0, $t->calls[1]['timeout']);
    }

    public function testUnknownOptionIsRefused(): void
    {
        [$client] = $this->client();
        $this->expectException(\InvalidArgumentException::class);
        $client->domains->list(options: ['max_retries' => 0]); // @phpstan-ignore argument.type
    }

    public function testListAllFollowsCursor(): void
    {
        [$client, $t] = $this->client([
            FakeTransport::json(['object' => 'list', 'data' => [['id' => 'a'], ['id' => 'b']], 'has_more' => true]),
            FakeTransport::json(['object' => 'list', 'data' => [['id' => 'c']], 'has_more' => false]),
        ]);
        $ids = [];
        foreach ($client->logs->listAll(limit: 2) as $event) {
            $ids[] = $event['id'];
        }
        $this->assertSame(['a', 'b', 'c'], $ids);
        $this->assertSame('limit=2', $t->query(0));
        $this->assertSame('limit=2&after=b', $t->query(1));
    }

    public function testListAllDropsBeforeAndLatest(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['data' => [['id' => 'a']], 'has_more' => true]), FakeTransport::json(['data' => [], 'has_more' => false])]);
        iterator_to_array($client->inboxes->threads->emails->listAll('in_1', 'th_1', limit: 1));
        $this->assertSame(['limit=1', 'limit=1&after=a'], [$t->query(0), $t->query(1)]);
    }

    public function testListAllStopsWithoutAnId(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['data' => [['name' => 'x']], 'has_more' => true])]);
        $this->assertCount(1, iterator_to_array($client->logs->listAll()));
        $this->assertCount(1, $t->calls);
    }

    public function testCustomHeaderDoesNotMakeAPostRetryable(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['error' => 'oops'], 500, self::NO_WAIT)], headers: ['Idempotency-Key' => 'x']);
        try {
            $client->broadcasts->sendTest('bc_1', ['to' => 'me@inbox.dev']);
            $this->fail('no exception');
        } catch (InternalServerException) {
            $this->assertCount(1, $t->calls);
        }
    }

    public function testCallersHeaderKeyIsKeptInAnyCase(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['id' => 'em_1'])]);
        $client->emails->send(['from' => 'a@yourapp.dev', 'to' => 'b@inbox.dev'], options: ['headers' => ['idempotency-key' => 'mine']]);
        $this->assertSame(['mine'], $t->headerValues(0, 'Idempotency-Key'));
    }

    public function testLongRetryAfterStopsRetries(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['error' => 'down'], 503, ['Retry-After' => '120'])]);
        try {
            $client->domains->list();
            $this->fail('no exception');
        } catch (InternalServerException) {
            $this->assertCount(1, $t->calls);
        }
    }

    public function testHugeRetryAfterStopsRetries(): void
    {
        [$client, $t] = $this->client([FakeTransport::json([], 503, ['Retry-After' => '10000000000'])]);
        try {
            $client->domains->list();
            $this->fail('no exception');
        } catch (InternalServerException) {
            $this->assertCount(1, $t->calls);
        }
    }

    public function testRetryAfterAsAnHttpDate(): void
    {
        [$client, $t] = $this->client([FakeTransport::json([], 503, ['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT']), FakeTransport::json([])]);
        $client->domains->list();
        $this->assertCount(2, $t->calls);
        $this->assertSame([0.0], $this->waits);
    }

    public function testUnreadableRetryAfterFallsBackToBackoff(): void
    {
        [$client, $t] = $this->client(array_fill(0, 2, FakeTransport::json(['error' => 'down'], 503, ['Retry-After' => 'NaN'])), maxRetries: 1);
        try {
            $client->domains->list();
            $this->fail('no exception');
        } catch (InternalServerException) {
            $this->assertCount(2, $t->calls);
            $this->assertCount(1, $this->waits);
            $this->assertGreaterThan(0.0, $this->waits[0]);
        }
    }

    public function testRedirectIsAnError(): void
    {
        [$client] = $this->client([new Response(302, ['Location' => 'https://smtpfa.st/login'])]);
        try {
            $client->domains->list();
            $this->fail('no exception');
        } catch (ApiException $e) {
            $this->assertSame(ApiException::class, $e::class);
            $this->assertSame(302, $e->status);
        }
    }

    public function testNotFound(): void
    {
        [$client] = $this->client([FakeTransport::json(['error' => 'Email not found'], 404)]);
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('404: Email not found');
        $client->emails->get('em_missing');
    }

    public function testEmptyAndTextAnswers(): void
    {
        [$client] = $this->client([new Response(204), new Response(200, ['Content-Type' => 'text/csv'], "email\nb@inbox.dev\n")]);
        $this->assertNull($client->domains->delete('dm_1'));
        $this->assertSame("email\nb@inbox.dev\n", $client->contacts->export(format: 'csv'));
    }

    public function testTypedExportAsksForJson(): void
    {
        [$client, $t] = $this->client([FakeTransport::json(['data' => []])]);
        $client->contacts->export();
        $this->assertStringContainsString('format=json', $t->query());
    }

    public function testBodies(): void
    {
        [$client, $t] = $this->client();
        $client->contacts->update('ct_1', ['segment_ids' => []]);
        $client->broadcasts->send('bc_1', []);
        $client->emails->sendBatch([]);
        $client->broadcasts->cancel('bc_1');
        $this->assertSame('{"segment_ids":[]}', $t->calls[0]['body']);
        $this->assertSame('{}', $t->calls[1]['body']);
        $this->assertSame('[]', $t->calls[2]['body']);
        $this->assertNull($t->calls[3]['body']);
        $this->assertNull($t->header(3, 'Content-Type'));
    }

    public function testBodyThatIsNotUtf8(): void
    {
        [$client, $t] = $this->client();
        $this->expectException(SMTPfastException::class);
        $client->emails->send(['to' => 'b@inbox.dev', 'subject' => "\xff"]);
    }

    public function testWebhookSignature(): void
    {
        $secret = 'whsec_test';
        $payload = json_encode(['type' => 'email.delivered', 'data' => ['id' => 'em_1']], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, $secret);
        $this->assertTrue(Webhooks::verifySignature($payload, $signature, $secret));
        $this->assertTrue(Webhooks::verifySignature($payload, ' ' . strtoupper($signature) . "\n", $secret));
        $this->assertFalse(Webhooks::verifySignature($payload . ' ', $signature, $secret));
        $this->assertFalse(Webhooks::verifySignature($payload, null, $secret));
        $this->assertFalse(Webhooks::verifySignature($payload, $signature, ''));
        $this->assertFalse(Webhooks::verifySignature($payload, substr($signature, 0, 63), $secret));
        $this->assertSame('email.delivered', Webhooks::parse($payload, ['X-SMTPfast-Signature' => $signature], $secret)['type']);
        $this->assertSame('email.delivered', Webhooks::parse($payload, ['x-smtpfast-signature' => [$signature]], $secret)['type']);
        $this->expectException(WebhookVerificationException::class);
        Webhooks::parse($payload, ['x-smtpfast-signature' => '00'], $secret);
    }

    public function testNonAsciiSignatureIsRejectedNotRaised(): void
    {
        $this->assertFalse(Webhooks::verifySignature('{}', 'é', 'secret'));
        $this->expectException(WebhookVerificationException::class);
        Webhooks::parse('{}', ['X-SMTPfast-Signature' => 'é'], 'secret');
    }

    /**
     * Run $test with these environment variables; null removes one.
     *
     * @param array<string, string|null> $vars
     */
    private function withEnv(array $vars, \Closure $test): void
    {
        $saved = [];
        foreach ($vars as $name => $value) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($value === null ? $name : "$name=$value");
        }
        try {
            $test();
        } finally {
            foreach ($saved as $name => [$env, $server, $process]) {
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                putenv($process === false ? $name : "$name=$process");
            }
        }
    }
}
