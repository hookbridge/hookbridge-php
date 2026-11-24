<?php

declare(strict_types=1);

namespace HookBridge\Response;

readonly class SendTestEventResponse
{
    public function __construct(
        public string $messageId,
        public string $status,
        public string $eventType,
        public bool $isTest,
    ) {}
}
