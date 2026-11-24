<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

readonly class RecoverJob
{
    public function __construct(
        public string $recoverJobId,
        public string $endpointId,
        public string $status,
        public int $createdCount,
        public int $requeuedCount,
        public DateTimeImmutable $since,
        public DateTimeImmutable $until,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $finishedAt = null,
    ) {}
}
