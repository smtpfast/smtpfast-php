<?php

declare(strict_types=1);

namespace SMTPfast;

use SMTPfast\Exception\SMTPfastException;
use SMTPfast\Exception\WebhookVerificationException;

/**
 * Check that a webhook request came from SMTPfast.
 *
 * The X-SMTPfast-Signature header is the hex HMAC-SHA256 of the raw request
 * body, keyed with the webhook's signing secret. Pass the body exactly as
 * received, before any JSON parsing.
 */
final class Webhooks
{
    public const SIGNATURE_HEADER = 'X-SMTPfast-Signature';

    public static function verifySignature(string $payload, ?string $signature, string $secret): bool
    {
        if ($signature === null || $signature === '' || $secret === '') {
            return false;
        }
        $signature = strtolower(trim($signature));
        if (preg_match('/^[0-9a-f]{64}$/D', $signature) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    /**
     * Verify the signature, then return the parsed event.
     *
     * @param iterable<string, string|list<string>|null> $headers the request headers, in any letter case
     *
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationException when the signature does not match
     * @throws SMTPfastException when the body is not a JSON object
     */
    public static function parse(string $payload, iterable $headers, string $secret): array
    {
        $signature = null;
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, self::SIGNATURE_HEADER) === 0) {
                $signature = is_array($value) ? ($value[0] ?? null) : $value;
                break;
            }
        }
        if (!self::verifySignature($payload, $signature, $secret)) {
            throw new WebhookVerificationException('Webhook signature does not match');
        }
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SMTPfastException('Webhook body is not JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($event)) {
            throw new SMTPfastException('Webhook body is not a JSON object');
        }

        return $event;
    }
}
