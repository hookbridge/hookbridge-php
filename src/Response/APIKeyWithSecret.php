<?php

declare(strict_types=1);

namespace HookBridge\Response;

/**
 * API key with secret (returned on creation).
 */
readonly class APIKeyWithSecret
{
    public function __construct(
        public string $keyId,
        public string $key,
        public string $prefix,
        public string $createdAt,
        public ?string $label = null,
    ) {}
}
