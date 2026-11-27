<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

readonly class EventMessage
{
    /**
     * @param string $endpointId Public endpoint id (ep_ followed by 32 hex characters)
     */
    public function __construct(
        public string $id,
        public string $endpointId,
        public string $status,
        public int $attemptCount,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?DateTimeImmutable $deletedAt = null,
    ) {}
}
