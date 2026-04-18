<?php

declare(strict_types=1);

namespace HookBridge\Response;

use DateTimeImmutable;

readonly class ReplayAllMessagesResponse
{
    public function __construct(
        public int $replayed,
        public int $failed,
        public int $stuck,
        public array $replayedMessageIds,
        public array $stuckMessageIds,
    ) {}
}

readonly class ReplayBatchResult
{
    public function __construct(
        public string $messageId,
        public string $status,
        public ?string $error = null,
    ) {}
}

readonly class ReplayBatchMessagesResponse
{
    public function __construct(
        public int $replayed,
        public int $failed,
        public int $stuck,
        public array $results,
    ) {}
}

readonly class AttemptRecord
{
    public function __construct(
        public string $id,
        public int $attemptNo,
        public DateTimeImmutable $createdAt,
        public ?int $responseStatus = null,
        public ?int $responseLatencyMs = null,
        public ?int $processingMs = null,
        public ?string $errorText = null,
        public ?array $responseHeaders = null,
        public ?string $retryType = null,
        public ?int $retryAfterSeconds = null,
        public ?string $resolvedIp = null,
        public ?array $requestHeaders = null,
        public ?int $dnsMs = null,
        public ?int $tcpConnectMs = null,
        public ?int $tlsHandshakeMs = null,
        public ?int $ttfbMs = null,
        public ?int $transferMs = null,
        public ?bool $connReused = null,
        public ?string $responseBodyUrl = null,
        public ?bool $responseBodyTruncated = null,
    ) {}
}

readonly class AttemptsResponse
{
    public function __construct(
        public array $attempts,
        public bool $hasMore,
    ) {}
}

readonly class Project
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $name,
        public string $status,
        public int $rateLimitDefault,
        public DateTimeImmutable $createdAt,
    ) {}
}

readonly class SigningKey
{
    public function __construct(
        public string $id,
        public string $keyHint,
        public DateTimeImmutable $createdAt,
    ) {}
}

readonly class CheckoutSession
{
    public function __construct(
        public string $sessionId,
        public string $checkoutUrl,
    ) {}
}

readonly class PortalSession
{
    public function __construct(
        public string $portalUrl,
    ) {}
}

readonly class SubscriptionLimits
{
    public function __construct(
        public string $plan,
        public int $messagesPerMonth,
        public int $maxProjects,
        public int $maxEndpoints,
        public int $retentionDays,
        public ?int $maxRetries = null,
    ) {}
}

readonly class SubscriptionUsage
{
    public function __construct(
        public int $messagesUsed,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
    ) {}
}

readonly class Subscription
{
    public function __construct(
        public string $plan,
        public string $status,
        public SubscriptionLimits $limits,
        public SubscriptionUsage $usage,
        public ?bool $cancelAtPeriodEnd = null,
        public ?DateTimeImmutable $currentPeriodEnd = null,
    ) {}
}

readonly class UsageHistoryRow
{
    public function __construct(
        public string $periodStart,
        public string $periodEnd,
        public int $messageCount,
        public int $overageCount,
        public ?int $planLimit,
    ) {}
}

readonly class UsageHistoryResponse
{
    public function __construct(
        public array $rows,
        public int $total,
        public int $limit,
        public int $offset,
        public bool $hasMore,
    ) {}
}

readonly class InvoiceLine
{
    public function __construct(
        public string $description,
        public int $amount,
        public int $quantity,
    ) {}
}

readonly class Invoice
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amountDue,
        public int $amountPaid,
        public string $currency,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public DateTimeImmutable $created,
        public array $lines,
        public ?string $invoicePdf = null,
        public ?string $hostedInvoiceUrl = null,
    ) {}
}

readonly class InvoicesResponse
{
    public function __construct(
        public array $invoices,
        public bool $hasMore,
    ) {}
}

readonly class CreateInboundEndpointResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public string $mode,
        public string $ingestUrl,
        public string $secretToken,
        public DateTimeImmutable $createdAt,
        public ?string $signingKeyId = null,
        public ?string $signingSecret = null,
        public ?string $keyHint = null,
    ) {}
}

readonly class InboundMessage
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $inboundEndpointId,
        public string $status,
        public int $attemptCount,
        public int $replayCount,
        public DateTimeImmutable $receivedAt,
        public DateTimeImmutable $updatedAt,
        public ?string $contentType = null,
        public ?int $sizeBytes = null,
        public ?string $payloadSha256 = null,
        public ?string $idempotencyKey = null,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?string $lastError = null,
        public ?int $responseStatus = null,
        public ?int $responseLatencyMs = null,
        public ?int $queueWaitMs = null,
        public ?int $totalDeliveryMs = null,
        public ?DateTimeImmutable $deliveredAt = null,
        public ?DateTimeImmutable $failedAt = null,
    ) {}
}

readonly class InboundEndpoint
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public string $mode,
        public bool $active,
        public bool $paused,
        public bool $verifyStaticToken,
        public bool $verifyHmac,
        public bool $verifyIpAllowlist,
        public int $ingestResponseCode,
        public array $idempotencyHeaderNames,
        public bool $signingEnabled,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $description = null,
    ) {}
}

readonly class InboundEndpointSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public string $mode,
        public bool $active,
        public bool $paused,
        public DateTimeImmutable $createdAt,
    ) {}
}

readonly class ListInboundEndpointsResponse
{
    public function __construct(
        public array $endpoints,
        public bool $hasMore,
        public ?string $nextCursor = null,
    ) {}
}

readonly class UpdateResult
{
    public function __construct(
        public string $id,
        public bool $updated,
    ) {}
}

readonly class DeleteResult
{
    public function __construct(
        public bool $deleted,
        public ?string $id = null,
    ) {}
}

readonly class PauseState
{
    public function __construct(
        public string $id,
        public bool $paused,
        public ?int $messagesRequeued = null,
    ) {}
}

readonly class PullEndpointCounts
{
    public function __construct(
        public ?int $stored = null,
        public ?int $fetched = null,
        public ?int $delivered = null,
        public ?int $total = null,
    ) {}
}

readonly class PullEndpoint
{
    public function __construct(
        public string $id,
        public string $mode,
        public string $ingestUrl,
        public bool $active,
        public bool $paused,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $name = null,
        public ?string $description = null,
        public ?int $retentionDays = null,
        public ?string $eventTypeSource = null,
        public ?string $eventTypePath = null,
        public ?PullEndpointCounts $counts = null,
        public ?bool $verifyStaticToken = null,
        public ?string $tokenHeaderName = null,
        public ?string $tokenQueryParam = null,
        public ?bool $verifyHmac = null,
        public ?string $hmacHeaderName = null,
        public ?string $timestampHeaderName = null,
        public ?int $timestampTtlSeconds = null,
        public ?bool $verifyIpAllowlist = null,
        public ?array $allowedCidrs = null,
        public ?int $ingestResponseCode = null,
        public ?array $idempotencyHeaderNames = null,
    ) {}
}

readonly class CreatePullEndpointResponse
{
    public function __construct(
        public string $id,
        public string $mode,
        public string $ingestUrl,
        public bool $active,
        public bool $paused,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $name = null,
        public ?string $description = null,
        public ?int $retentionDays = null,
        public ?string $eventTypeSource = null,
        public ?string $eventTypePath = null,
        public ?PullEndpointCounts $counts = null,
        public ?bool $verifyStaticToken = null,
        public ?string $tokenHeaderName = null,
        public ?string $tokenQueryParam = null,
        public ?bool $verifyHmac = null,
        public ?string $hmacHeaderName = null,
        public ?string $timestampHeaderName = null,
        public ?int $timestampTtlSeconds = null,
        public ?bool $verifyIpAllowlist = null,
        public ?array $allowedCidrs = null,
        public ?int $ingestResponseCode = null,
        public ?array $idempotencyHeaderNames = null,
        public ?string $secretToken = null,
    ) {}
}

readonly class PullEndpointSummary
{
    public function __construct(
        public string $id,
        public bool $active,
        public bool $paused,
        public string $ingestUrl,
        public DateTimeImmutable $createdAt,
        public ?string $name = null,
    ) {}
}

readonly class ListPullEndpointsResponse
{
    public function __construct(
        public array $endpoints,
        public bool $hasMore,
        public ?string $nextCursor = null,
    ) {}
}

readonly class PullTimingBreakdown
{
    public function __construct(
        public ?int $ingestProcessingMs = null,
        public ?int $timeToFetchMs = null,
        public ?int $timeToAckMs = null,
        public ?int $totalLifecycleMs = null,
    ) {}
}

readonly class PullEventSummary
{
    public function __construct(
        public string $id,
        public string $status,
        public int $sizeBytes,
        public DateTimeImmutable $receivedAt,
        public ?string $eventType = null,
        public ?DateTimeImmutable $fetchedAt = null,
        public ?DateTimeImmutable $deliveredAt = null,
        public ?PullTimingBreakdown $timing = null,
    ) {}
}

readonly class PullEventDetail
{
    public function __construct(
        public string $id,
        public string $status,
        public string $contentType,
        public mixed $payload,
        public int $sizeBytes,
        public DateTimeImmutable $receivedAt,
        public ?string $eventType = null,
        public ?array $headers = null,
        public ?DateTimeImmutable $fetchedAt = null,
        public ?DateTimeImmutable $deliveredAt = null,
        public ?PullTimingBreakdown $timing = null,
    ) {}
}

readonly class ListPullEventsResponse
{
    public function __construct(
        public array $events,
        public bool $hasMore,
        public ?string $nextCursor = null,
    ) {}
}

readonly class AckPullEventsResponse
{
    public function __construct(
        public int $acknowledged,
    ) {}
}

readonly class PullLogEntry
{
    public function __construct(
        public string $eventId,
        public string $pullEndpointId,
        public string $status,
        public int $sizeBytes,
        public DateTimeImmutable $receivedAt,
        public ?string $endpointName = null,
        public ?string $eventType = null,
        public ?DateTimeImmutable $fetchedAt = null,
        public ?DateTimeImmutable $deliveredAt = null,
        public ?PullTimingBreakdown $timing = null,
    ) {}
}

readonly class PullLogsResponse
{
    public function __construct(
        public array $entries,
        public bool $hasMore,
        public ?string $nextCursor = null,
    ) {}
}

readonly class InboundLogEntry
{
    public function __construct(
        public string $messageId,
        public string $inboundEndpointId,
        public string $endpoint,
        public string $status,
        public int $attemptCount,
        public DateTimeImmutable $receivedAt,
        public ?DateTimeImmutable $deliveredAt = null,
        public ?int $responseStatus = null,
        public ?int $responseLatencyMs = null,
        public ?string $lastError = null,
        public ?int $totalDeliveryMs = null,
    ) {}
}

readonly class InboundLogsResponse
{
    public function __construct(
        public array $entries,
        public bool $hasMore,
        public ?string $nextCursor = null,
    ) {}
}

readonly class InboundMetrics
{
    public function __construct(
        public string $window,
        public int $totalMessages,
        public int $succeeded,
        public int $failed,
        public int $retries,
        public float $successRate,
        public int $avgLatencyMs,
        public int $avgDeliveryTimeMs,
    ) {}
}

readonly class TimeSeriesBucket
{
    public function __construct(
        public DateTimeImmutable $timestamp,
        public int $succeeded,
        public int $failed,
        public int $retrying,
        public int $total,
        public int $avgLatencyMs,
    ) {}
}

readonly class TimeSeriesMetrics
{
    public function __construct(
        public string $window,
        public array $buckets,
    ) {}
}

readonly class PullTimeSeriesBucket
{
    public function __construct(
        public DateTimeImmutable $timestamp,
        public int $succeeded,
        public int $stored,
        public int $fetched,
        public int $total,
    ) {}
}

readonly class PullTimeSeriesMetrics
{
    public function __construct(
        public string $window,
        public array $buckets,
    ) {}
}

readonly class InboundRejection
{
    public function __construct(
        public string $id,
        public string $reasonCode,
        public DateTimeImmutable $receivedAt,
        public ?string $inboundEndpointId = null,
        public ?string $reasonDetail = null,
        public ?string $sourceIp = null,
        public ?string $headersRedacted = null,
    ) {}
}

readonly class InboundRejectionsResponse
{
    public function __construct(
        public array $entries,
        public bool $hasMore,
        public ?string $nextCursor = null,
    ) {}
}

readonly class ListenMessage
{
    public function __construct(
        public string $messageId,
        public string $contentType,
        public array $headers,
        public int $sizeBytes,
        public DateTimeImmutable $receivedAt,
        public mixed $body = null,
        public ?string $bodyEncoding = null,
        public ?string $bodyError = null,
    ) {}
}

readonly class ListenInboundEndpointResponse
{
    public function __construct(
        public array $messages,
        public ?string $nextCursor = null,
    ) {}
}

readonly class ExportRecord
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $status,
        public DateTimeImmutable $filterStartTime,
        public DateTimeImmutable $filterEndTime,
        public DateTimeImmutable $createdAt,
        public ?string $filterStatus = null,
        public ?string $filterEndpointId = null,
        public ?int $rowCount = null,
        public ?int $fileSizeBytes = null,
        public ?string $errorMessage = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $completedAt = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}
}

readonly class DeleteMessageResult
{
    public function __construct(
        public string $messageId,
        public DateTimeImmutable $deletedAt,
        public bool $alreadyDeleted,
    ) {}
}

readonly class DeleteEventResult
{
    public function __construct(
        public string $eventId,
        public DateTimeImmutable $deletedAt,
        public bool $alreadyDeleted,
    ) {}
}

readonly class DeleteBatchItemResult
{
    public function __construct(
        public string $messageId,
        public string $outcome,
        public ?DateTimeImmutable $deletedAt = null,
    ) {}
}

readonly class DeleteBatchResult
{
    /** @param list<DeleteBatchItemResult> $results */
    public function __construct(
        public array $results,
        public int $deletedCount,
        public int $alreadyDeletedCount,
        public int $notFoundCount,
    ) {}
}

readonly class DeleteEventBatchItemResult
{
    public function __construct(
        public string $eventId,
        public string $outcome,
        public ?DateTimeImmutable $deletedAt = null,
    ) {}
}

readonly class DeleteEventBatchResult
{
    /** @param list<DeleteEventBatchItemResult> $results */
    public function __construct(
        public array $results,
        public int $deletedCount,
        public int $alreadyDeletedCount,
        public int $notFoundCount,
    ) {}
}

readonly class DeletePartialError
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}
}

readonly class DeleteAllResult
{
    /** @param list<string> $deletedMessageIds */
    public function __construct(
        public int $deleted,
        public array $deletedMessageIds,
        public ?DeletePartialError $error = null,
    ) {}
}

readonly class DeletePullEventsAllResult
{
    /** @param list<string> $deletedEventIds */
    public function __construct(
        public int $deleted,
        public array $deletedEventIds,
        public ?DeletePartialError $error = null,
    ) {}
}

readonly class ActorLookupResult
{
    /**
     * @param array<string, array{email: string}>|null $users
     * @param array<string, array{label: string}>|null $apiKeys
     */
    public function __construct(
        public ?array $users = null,
        public ?array $apiKeys = null,
    ) {}
}
