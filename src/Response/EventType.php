<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

readonly class EventType
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public ?string $group,
        public ?array $schema,
        public mixed $samplePayload,
        public bool $deprecated,
        public bool $public,
        public bool $autoCreated,
        public ?DateTimeImmutable $archivedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
