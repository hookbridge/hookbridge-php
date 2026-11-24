<?php

declare(strict_types=1);

namespace HookBridge\Response;

readonly class SendEventResponse
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $status,
        public ?string $messageId = null,
    ) {}
}
