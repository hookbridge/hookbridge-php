<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

/**
 * Summary endpoint info for list responses.
 */
readonly class EndpointSummary
{
    public function __construct(
        public string $id,
        public string $url,
        public ?string $description,
        public DateTimeImmutable $createdAt,
    ) {}
}
