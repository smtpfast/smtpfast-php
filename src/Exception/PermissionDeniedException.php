<?php

declare(strict_types=1);

namespace SMTPfast\Exception;

/**
 * A 403 answer: the key lacks the scope, or the domain is not verified.
 */
class PermissionDeniedException extends ApiException
{
}
