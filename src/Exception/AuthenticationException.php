<?php

declare(strict_types=1);

namespace HookBridge\Exception;

/**
 * Thrown when authentication fails (invalid or missing API key).
 */
class AuthenticationException extends HookBridgeException
{
    public function __construct(string $message, ?string $requestId = null)
    {
        parent::__construct(
            message: $message,
            errorCode: 'AUTHENTICATION_ERROR',
            requestId: $requestId,
            statusCode: 401
        );
    }
}
