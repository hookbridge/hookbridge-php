<?php

declare(strict_types=1);

namespace HookBridge\Response;

/**
 * API key information.
 */
readonly class APIKey
{
    public function __construct(
        public string $keyId,
        public string $prefix,
        public string $createdAt,
        public ?string $label = null,
        public ?string $lastUsedAt = null,
    ) {}
}
