<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

/**
 * Full message details.
 */
readonly class Message
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $endpointId,
        public string $status,
        public int $attemptCount,
        public int $replayCount,
        public string $contentType,
        public int $sizeBytes,
        public string $payloadSha256,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $idempotencyKey = null,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?string $lastError = null,
        public ?int $responseStatus = null,
        public ?int $responseLatencyMs = null,
    ) {}
}
