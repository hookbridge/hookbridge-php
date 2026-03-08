<?php

declare(strict_types=1);

namespace HookBridge\Response;

/**
 * Summary of a message (used in logs and DLQ).
 */
readonly class MessageSummary
{
    public function __construct(
        public string $messageId,
        public string $endpoint,
        public string $status,
        public int $attemptCount,
        public string $createdAt,
        public ?string $deliveredAt = null,
        public ?int $responseStatus = null,
        public ?int $responseLatencyMs = null,
        public ?string $lastError = null,
    ) {}
}
