<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

/**
 * Response from creating an endpoint.
 */
readonly class CreateEndpointResponse
{
    public function __construct(
        public string $id,
        public string $url,
        public string $signingSecret,
        public ?string $description,
        public DateTimeImmutable $createdAt,
    ) {}
}
