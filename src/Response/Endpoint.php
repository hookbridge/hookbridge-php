<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

/**
 * Full endpoint details.
 */
readonly class Endpoint
{
    /**
     * @param array<string, string>|null $headers
     */
    public function __construct(
        public string $id,
        public string $url,
        public ?string $description,
        public bool $hmacEnabled,
        public ?int $rateLimitRps,
        public ?int $burst,
        public ?array $headers,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
