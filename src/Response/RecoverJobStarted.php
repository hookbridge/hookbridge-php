<?php

declare(strict_types=1);

namespace HookBridge\Response;

readonly class RecoverJobStarted
{
    public function __construct(
        public string $recoverJobId,
        public string $status,
    ) {}
}
