<?php

declare(strict_types=1);

namespace HookBridge\Response;

/**
 * Response from listing endpoints.
 */
readonly class ListEndpointsResponse
{
    /**
     * @param EndpointSummary[] $endpoints
     */
    public function __construct(
        public array $endpoints,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {}
}
