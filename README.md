# SMTPfast PHP SDK

The official PHP SDK for the [SMTPfast](https://smtpfa.st) API. It covers every endpoint in the API, with a PHPStan array shape for every request and response. It needs PHP 8.2+ with the curl extension and has no other dependencies.

```bash
composer require smtpfast/smtpfast-php
```

For Laravel, [smtpfast/smtpfast-laravel](https://github.com/smtpfast/smtpfast-laravel) adds a mail driver on top of this SDK.

## Send an email

```php
use SMTPfast\Client;

$client = new Client(); // reads SMTPFAST_API_KEY

$email = $client->emails->send([
    'from' => 'Acme <hello@yourdomain.com>',
    'to' => ['customer@example.org'],
    'subject' => 'Your receipt',
    'html' => '<p>Thanks for your order.</p>',
    'category' => 'transactional',
]);
echo $email['id'];
```

Email is marketing unless you set `category` to `transactional`. Marketing email gets a one-click unsubscribe header and is not sent to people who unsubscribed. Set `transactional` on receipts, password resets and account alerts so a marketing opt-out does not block them.

Requests and responses are plain PHP arrays. `SMTPfast\Types` describes each one as a PHPStan array shape, so PHPStan, Psalm and your editor know every field. A new field or value from the API never breaks your code, because the SDK does not validate responses at runtime.

Every API operation is a method, named after its path: `$client->domains->verify($id)`, `$client->contacts->segments->add($contactId, $segmentId)`, `$client->inboxes->threads->emails->reply($inboxId, $threadId, $emailId, $params)`. Path parameters come first, then the request body, then the query parameters, which read best as named arguments: `$client->emails->list(limit: 20, status: 'bounced')`.

An empty PHP array is sent as a JSON list. Where the API expects an empty object inside a request, pass `new \stdClass()`.

## Options

```php
$client = new Client(
    apiKey: 'sf_...',                    // default: SMTPFAST_API_KEY
    baseUrl: 'https://smtpfa.st/api',
    maxRetries: 2,                       // after 429 (with Retry-After), 408, 5xx and dropped connections
    timeout: 60.0,                       // per attempt, in seconds
);

$client->emails->send($params, idempotencyKey: 'order-1234', options: ['timeout' => 10]);
```

`options` also takes `maxRetries` and `headers` for one call. The client sends requests with curl. To use another HTTP client, implement `SMTPfast\Http\Transport` and pass it as `transport:`.

## Retries and idempotency

Reads, `PUT` and `DELETE` are retried after a 5xx answer or a dropped connection. A send, reply or forward is retried with the same `Idempotency-Key`, so the API returns the first answer instead of sending twice. When you do not give a key, the SDK makes one per call. Other `POST` and `PATCH` calls are retried only after a per-second rate limit, which the API rejects before doing any work.

## Errors

Every exception is an `SMTPfast\Exception\SMTPfastException`. An answer outside 2xx throws an `ApiException` with `status`, `body` and `headers`, as one of `BadRequestException`, `AuthenticationException`, `PermissionDeniedException`, `NotFoundException`, `ConflictException`, `UnprocessableEntityException`, `RateLimitException` or `InternalServerException`. No answer at all throws `ConnectionException` or `TimeoutException`.

```php
use SMTPfast\Exception\NotFoundException;

try {
    $client->emails->get('em_missing');
} catch (NotFoundException $e) {
    echo $e->status, ' ', json_encode($e->body);
}
```

## Pagination

Lists that page with a cursor also have an `...All` method that fetches the next pages as you iterate:

```php
foreach ($client->emails->listAll(limit: 100) as $email) {
    echo $email['id'], ' ', $email['status'], "\n";
}
```

## Webhooks

```php
use SMTPfast\Webhooks;

// The raw body, before any JSON parsing.
$event = Webhooks::parse(file_get_contents('php://input'), getallheaders(), $secret);
if ($event['type'] === 'email.bounced') {
    // ...
}
```

`Webhooks::verifySignature($payload, $signature, $secret)` returns a bool if you only need the check. `parse` throws a `WebhookVerificationException` when the signature does not match.

## How this SDK is made

The SDK is generated from the [OpenAPI document](https://smtpfa.st/api/v1/openapi.json), so it follows the API without hand edits. `scripts/generate.py` (Python 3 standard library only) writes `src/Types.php` and `src/Resources/`, naming every method from the `x-sdk-group` and `x-sdk-method` the spec gives each operation; the SDKs for other languages use the same names. `Client.php`, `Core.php`, `Webhooks.php`, `Http/` and `Exception/` are written by hand.

```bash
python3 scripts/pull_spec.py   # download the published spec into spec/openapi.json
python3 scripts/generate.py    # regenerate src/Types.php and src/Resources/
composer install
vendor/bin/phpstan             # static analysis, including the generated array shapes
vendor/bin/phpunit             # unit tests, including one call per operation
SMTPFAST_API_KEY=sf_... vendor/bin/phpunit tests/LiveTest.php   # read-only calls, checked against the spec
```

A daily workflow pulls the spec and opens a pull request when it changed.

## Releases

Packagist publishes a version for every git tag, so there is no release workflow. To release, update `Client::VERSION`, then tag the commit, for example `git tag v0.1.0 && git push origin v0.1.0`.

## License

MIT
