<?php

declare(strict_types=1);

namespace HookBridge\Response;

/**
 * Response from sending a webhook.
 */
readonly class SendResponse
{
    public function __construct(
        public string $messageId,
        public string $status,
    ) {}
}
