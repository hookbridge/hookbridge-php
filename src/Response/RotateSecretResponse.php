<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

/**
 * Response from rotating an endpoint secret.
 */
readonly class RotateSecretResponse
{
    public function __construct(
        public string $id,
        public string $signingSecret,
        public ?string $keyHint = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}
}
