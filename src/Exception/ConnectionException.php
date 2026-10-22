<?php

declare(strict_types=1);

namespace SMTPfast\Exception;

/**
 * No answer arrived: DNS, TLS, a reset connection, or a timeout.
 */
class ConnectionException extends SMTPfastException
{
    public function __construct(string $message = 'Could not connect to the SMTPfast API', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
