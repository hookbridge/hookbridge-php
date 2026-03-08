<?php

declare(strict_types=1);

namespace HookBridge\Response;

/**
 * Response from rotating an endpoint secret.
 */
readonly class RotateSecretResponse
{
    public function __construct(
        public string $id,
        public string $signingSecret,
    ) {}
}
