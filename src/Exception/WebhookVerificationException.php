<?php

declare(strict_types=1);

namespace SMTPfast\Exception;

/**
 * The webhook signature is missing or does not match the body.
 */
class WebhookVerificationException extends SMTPfastException
{
}
