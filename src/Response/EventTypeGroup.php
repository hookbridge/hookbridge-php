<?php

declare(strict_types=1);

namespace HookBridge\Response;

readonly class EventTypeGroup
{
    public function __construct(
        public string $name,
        public int $count,
    ) {}
}
