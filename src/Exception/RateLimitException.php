<?php

declare(strict_types=1);

namespace HookBridge\Exception;

/**
 * Thrown when rate limit is exceeded.
 */
class RateLimitException extends HookBridgeException
{
    public readonly ?int $retryAfter;

    public function __construct(
        string $message,
        ?string $requestId = null,
        ?int $retryAfter = null,
    ) {
        parent::__construct(
            message: $message,
            errorCode: 'RATE_LIMIT_EXCEEDED',
            requestId: $requestId,
            statusCode: 429
        );
        $this->retryAfter = $retryAfter;
    }
}
