<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

readonly class EventMessage
{
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
