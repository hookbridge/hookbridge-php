<?php

declare(strict_types=1);

namespace HookBridge\Exception;

/**
 * Thrown when an idempotency key conflict occurs.
 */
class IdempotencyException extends HookBridgeException
{
    public function __construct(string $message, ?string $requestId = null)
    {
        parent::__construct(
            message: $message,
            errorCode: 'IDEMPOTENCY_MISMATCH',
            requestId: $requestId,
            statusCode: 409
        );
    }
}
