<?php

declare(strict_types=1);

namespace HookBridge\Response;

/**
 * Response from updating an endpoint.
 */
readonly class UpdateEndpointResponse
{
    public function __construct(
        public string $id,
        public bool $updated,
        public ?EventSubscriptions $subscriptions = null,
    ) {}
}
