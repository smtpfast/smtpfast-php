<?php

declare(strict_types=1);

namespace SMTPfast\Exception;

/**
 * A 401 answer: a missing or wrong API key.
 */
class AuthenticationException extends ApiException
{
}
