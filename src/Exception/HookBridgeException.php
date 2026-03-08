<?php

declare(strict_types=1);

namespace HookBridge\Exception;

use Exception;

/**
 * Base exception for all HookBridge errors.
 */
class HookBridgeException extends Exception
{
    public readonly ?string $errorCode;
    public readonly ?string $requestId;
    public readonly ?int $statusCode;

    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?string $requestId = null,
        ?int $statusCode = null,
    ) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->requestId = $requestId;
        $this->statusCode = $statusCode;
    }
}
