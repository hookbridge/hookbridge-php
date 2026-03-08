<?php

declare(strict_types=1);

namespace HookBridge\Exception;

/**
 * Thrown when request validation fails.
 */
class ValidationException extends HookBridgeException
{
    public function __construct(string $message, ?string $requestId = null)
    {
        parent::__construct(
            message: $message,
            errorCode: 'VALIDATION_ERROR',
            requestId: $requestId,
            statusCode: 400
        );
    }
}
