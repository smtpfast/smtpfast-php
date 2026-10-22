<?php

declare(strict_types=1);

namespace SMTPfast\Exception;

use SMTPfast\Http\Response;

/**
 * The API answered with a status outside 2xx.
 */
class ApiException extends SMTPfastException
{
    private const BY_STATUS = [
        400 => BadRequestException::class,
        401 => AuthenticationException::class,
        403 => PermissionDeniedException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        422 => UnprocessableEntityException::class,
        429 => RateLimitException::class,
    ];

    /**
     * @param mixed $body the decoded JSON body, or the raw text when it is not JSON
     * @param array<string, string> $headers header names in lower case
     */
    final public function __construct(
        public readonly int $status,
        public readonly mixed $body,
        public readonly array $headers = [],
    ) {
        parent::__construct(self::message($status, $body), $status);
    }

    public static function fromResponse(Response $response): self
    {
        try {
            $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = $response->body;
        }
        $class = self::BY_STATUS[$response->status] ?? ($response->status >= 500 ? InternalServerException::class : self::class);

        return new $class($response->status, $body, $response->headers);
    }

    private static function message(int $status, mixed $body): string
    {
        if (is_array($body) && isset($body['error']) && is_string($body['error'])) {
            return $status . ': ' . $body['error'];
        }
        if (is_string($body) && trim($body) !== '') {
            $text = trim($body);

            return $status . ': ' . (preg_match('/^.{0,300}/su', $text, $match) === 1 ? $match[0] : substr($text, 0, 300));
        }

        return $status . ': the SMTPfast API answered with an error';
    }
}
