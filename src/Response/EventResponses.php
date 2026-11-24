<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

/**
 * Which events an endpoint receives. If both lists are empty the endpoint receives all events.
 * A null list is left out of requests.
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
        $body = [];
        if ($this->eventTypes !== null) {
            $body['event_types'] = $this->eventTypes;
        }
        if ($this->groups !== null) {
            $body['groups'] = $this->groups;
        }

        return $body;
    }
}

readonly class SendEventResponse
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $status,
        public ?string $messageId = null,
    ) {}
}

readonly class EventType
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public ?string $group,
        public ?array $schema,
        public mixed $samplePayload,
        public bool $deprecated,
        public bool $public,
        public bool $autoCreated,
        public ?DateTimeImmutable $archivedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}

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

readonly class EventTypeGroup
{
    public function __construct(
        public string $name,
        public int $count,
    ) {}
}

readonly class Event
{
    /**
     * @param array<string, string>|null $headers
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $eventType,
        public string $payloadSha256,
        public string $contentType,
        public int $sizeBytes,
        public string $fanoutStatus,
        public int $matchedCount,
        public DateTimeImmutable $createdAt,
        public bool $payloadAvailable,
        public ?array $headers = null,
        public ?string $idempotencyKey = null,
        public ?int $retentionHours = null,
        public ?string $targetEndpointId = null,
        public ?DateTimeImmutable $fannedOutAt = null,
        public ?DateTimeImmutable $deletedAt = null,
    ) {}
}

readonly class EventMessage
{
    public function __construct(
        public string $id,
        public string $endpointId,
        public string $status,
        public int $attemptCount,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?DateTimeImmutable $deletedAt = null,
    ) {}
}

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

readonly class EventDetail
{
    /**
     * @param array<string, string>|null $headers
     * @param EventMessage[] $messages
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $eventType,
        public string $payloadSha256,
        public string $contentType,
        public int $sizeBytes,
        public string $fanoutStatus,
        public int $matchedCount,
        public DateTimeImmutable $createdAt,
        public bool $payloadAvailable,
        public array $messages,
        public bool $messagesHasMore,
        public ?array $headers = null,
        public ?string $idempotencyKey = null,
        public ?int $retentionHours = null,
        public ?string $targetEndpointId = null,
        public ?DateTimeImmutable $fannedOutAt = null,
        public ?DateTimeImmutable $deletedAt = null,
        public ?string $payloadUrl = null,
        public ?string $messagesNextCursor = null,
    ) {}
}

readonly class RecoverJobStarted
{
    public function __construct(
        public string $recoverJobId,
        public string $status,
    ) {}
}

readonly class RecoverJob
{
    public function __construct(
        public string $recoverJobId,
        public string $endpointId,
        public string $status,
        public int $createdCount,
        public int $requeuedCount,
        public DateTimeImmutable $since,
        public DateTimeImmutable $until,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $finishedAt = null,
    ) {}
}

readonly class SendTestEventResponse
{
    public function __construct(
        public string $messageId,
        public string $status,
        public string $eventType,
        public bool $isTest,
    ) {}
}
