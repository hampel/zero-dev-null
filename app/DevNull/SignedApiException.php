<?php

namespace App\DevNull;

/**
 * A refusal from the signed API, with its status and the `code` that comes beside its message,
 * so a caller can tell an unapproved device from a rate limit without reading the message.
 */
class SignedApiException extends DevNullException
{
    public function __construct(string $message, public readonly int $status, public readonly ?string $apiCode = null)
    {
        parent::__construct($message);
    }
}
