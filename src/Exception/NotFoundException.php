<?php

declare(strict_types=1);

namespace HookBridge\Exception;

/**
 * Thrown when a requested resource is not found.
 */
class NotFoundException extends HookBridgeException
{
    public function __construct(string $message, ?string $requestId = null)
    {
        parent::__construct(
            message: $message,
            errorCode: 'NOT_FOUND',
            requestId: $requestId,
            statusCode: 404
        );
    }
}
