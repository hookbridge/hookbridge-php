<?php

declare(strict_types=1);

namespace HookBridge\Response;

/**
 * Aggregated delivery metrics.
 */
readonly class Metrics
{
    public function __construct(
        public string $window,
        public int $totalMessages,
        public int $succeeded,
        public int $failed,
        public int $retries,
        public float $successRate,
        public int $avgLatencyMs,
    ) {}
}
