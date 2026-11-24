<?php

declare(strict_types=1);

namespace HookBridge\Response;

readonly class ListEventsResponse
{
    /**
     * @param Event[] $events
     */
    public function __construct(
        public array $events,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {}
}
