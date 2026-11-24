<?php

declare(strict_types=1);

namespace HookBridge\Response;

readonly class ListEventTypesResponse
{
    /**
     * @param EventType[] $eventTypes
     */
    public function __construct(
        public array $eventTypes,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {}
}
