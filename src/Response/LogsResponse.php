<?php

declare(strict_types=1);

namespace HookBridge\Response;

/**
 * Response from querying logs.
 */
readonly class LogsResponse
{
    /**
     * @param MessageSummary[] $messages
     */
    public function __construct(
        public array $messages,
        public bool $hasMore,
        public ?string $nextCursor = null,
    ) {}
}
