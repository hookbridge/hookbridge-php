<?php

declare(strict_types=1);

namespace HookBridge\Exception;

/**
 * Thrown when replay limit is exceeded.
 */
class ReplayLimitException extends HookBridgeException
{
    public function __construct(string $message, ?string $requestId = null)
    {
        parent::__construct(
            message: $message,
            errorCode: 'REPLAY_LIMIT_EXCEEDED',
            requestId: $requestId,
            statusCode: 429
        );
    }
}
