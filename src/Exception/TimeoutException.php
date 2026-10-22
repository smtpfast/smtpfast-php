<?php

declare(strict_types=1);

namespace SMTPfast\Exception;

/**
 * No answer arrived within the timeout.
 */
class TimeoutException extends ConnectionException
{
    public function __construct(float $timeout, string $detail = '', ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('The SMTPfast API did not answer within %g s', $timeout) . ($detail !== '' ? ': ' . $detail : ''), $previous);
    }
}
