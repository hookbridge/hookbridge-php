<?php

declare(strict_types=1);

namespace HookBridge\Response;

/**
 * Which events an endpoint receives. If both lists are empty the endpoint receives all events.
 * A null list is sent as an empty list.
 */
readonly class EventSubscriptions
{
    /**
     * @param list<string>|null $eventTypes Exact names ("order.created") or wildcards ("order.*")
     * @param list<string>|null $groups
     */
    public function __construct(
        public ?array $eventTypes = null,
        public ?array $groups = null,
    ) {}

    public function toArray(): array
    {
        return [
            'event_types' => $this->eventTypes ?? [],
            'groups' => $this->groups ?? [],
        ];
    }
}
