<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

readonly class EventDetail
{
    /**
     * @param array<string, string>|null $headers
     * @param string|null $targetEndpointId Public endpoint id (ep_ followed by 32 hex characters)
     * @param array<string, int> $statusCounts Message count per status (including "deleted")
     * @param EventMessage[] $messages
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $eventType,
        public string $payloadSha256,
        public string $contentType,
        public int $sizeBytes,
        public string $fanoutStatus,
        public int $matchedCount,
        public DateTimeImmutable $createdAt,
        public bool $payloadAvailable,
        public array $messages,
        public bool $messagesHasMore,
        public ?array $headers = null,
        public ?string $idempotencyKey = null,
        public ?int $retentionHours = null,
        public ?string $targetEndpointId = null,
        public ?DateTimeImmutable $fannedOutAt = null,
        public ?DateTimeImmutable $deletedAt = null,
        public ?string $payloadUrl = null,
        public ?string $messagesNextCursor = null,
        public array $statusCounts = [],
    ) {}
}
