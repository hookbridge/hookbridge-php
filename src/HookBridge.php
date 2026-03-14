<?php

declare(strict_types=1);

namespace HookBridge;

require_once __DIR__ . '/Response/ParityResponses.php';

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use HookBridge\Exception\AuthenticationException;
use HookBridge\Exception\HookBridgeException;
use HookBridge\Exception\IdempotencyException;
use HookBridge\Exception\NotFoundException;
use HookBridge\Exception\RateLimitException;
use HookBridge\Exception\ReplayLimitException;
use HookBridge\Exception\ValidationException;
use HookBridge\Response\APIKey;
use HookBridge\Response\APIKeyWithSecret;
use HookBridge\Response\CreateEndpointResponse;
use HookBridge\Response\DLQResponse;
use HookBridge\Response\Endpoint;
use HookBridge\Response\EndpointSummary;
use HookBridge\Response\ListEndpointsResponse;
use HookBridge\Response\LogsResponse;
use HookBridge\Response\Message;
use HookBridge\Response\MessageSummary;
use HookBridge\Response\Metrics;
use HookBridge\Response\PortalSession;
use HookBridge\Response\Project;
use HookBridge\Response\ReplayAllMessagesResponse;
use HookBridge\Response\ReplayBatchMessagesResponse;
use HookBridge\Response\RotateSecretResponse;
use HookBridge\Response\SigningKey;
use HookBridge\Response\SendResponse;
use HookBridge\Response\CheckoutSession;
use HookBridge\Response\CreateInboundEndpointResponse as InboundEndpointCreatedResponse;
use HookBridge\Response\DeleteResult;
use HookBridge\Response\ExportRecord;
use HookBridge\Response\InboundEndpoint;
use HookBridge\Response\InboundEndpointSummary;
use HookBridge\Response\InboundLogsResponse;
use HookBridge\Response\InboundMetrics;
use HookBridge\Response\InboundRejection;
use HookBridge\Response\InboundRejectionsResponse;
use HookBridge\Response\UsageHistoryResponse;
use HookBridge\Response\InvoicesResponse;
use HookBridge\Response\ListInboundEndpointsResponse;
use HookBridge\Response\PauseState;
use HookBridge\Response\TimeSeriesBucket;
use HookBridge\Response\TimeSeriesMetrics;
use HookBridge\Response\UpdateResult;

/**
 * HookBridge API client.
 *
 * @example
 * $client = new HookBridge('hb_live_xxxxxxxxxxxxxxxxxxxx');
 *
 * // Create an endpoint
 * $endpoint = $client->createEndpoint(url: 'https://customer.app/webhooks');
 *
 * // Send a webhook
 * $result = $client->send(
 *     endpointId: $endpoint->id,
 *     payload: ['event' => 'order.created', 'order_id' => '12345']
 * );
 * echo $result->messageId;
 */
class HookBridge
{
    private const DEFAULT_BASE_URL = 'https://api.hookbridge.io';
    private const DEFAULT_SEND_URL = 'https://send.hookbridge.io';
    private const DEFAULT_TIMEOUT = 30.0;
    private const DEFAULT_RETRIES = 3;
    private const USER_AGENT = 'hookbridge-php/1.4.0';

    private Client $client;
    private Client $sendClient;
    private string $baseUrl;
    private string $sendUrl;
    private int $retries;

    /**
     * Create a new HookBridge client.
     *
     * @param string $apiKey Your HookBridge API key (starts with hb_live_ or hb_test_)
     * @param string|null $baseUrl Base URL for the management API (default: https://api.hookbridge.io)
     * @param string|null $sendUrl Base URL for the webhook send API (default: https://send.hookbridge.io)
     * @param float $timeout Request timeout in seconds (default: 30)
     * @param int $retries Number of retries for failed requests (default: 3)
     *
     * @throws ValidationException If API key is empty
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        ?string $sendUrl = null,
        float $timeout = self::DEFAULT_TIMEOUT,
        int $retries = self::DEFAULT_RETRIES,
    ) {
        if (empty($apiKey)) {
            throw new ValidationException('API key is required');
        }

        $this->baseUrl = rtrim($baseUrl ?? getenv('HOOKBRIDGE_BASE_URL') ?: self::DEFAULT_BASE_URL, '/');
        $this->sendUrl = rtrim($sendUrl ?? getenv('HOOKBRIDGE_SEND_URL') ?: self::DEFAULT_SEND_URL, '/');
        $this->retries = $retries;

        $commonHeaders = [
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ];

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $timeout,
            'headers' => $commonHeaders,
        ]);

        $this->sendClient = new Client([
            'base_uri' => $this->sendUrl,
            'timeout' => $timeout,
            'headers' => $commonHeaders,
        ]);
    }

    /**
     * Send a webhook for delivery.
     *
     * @param string $endpointId ID of the endpoint to deliver to (e.g., "ep_550e8400e29b41d4a716446655440000")
     * @param array<string, mixed> $payload JSON payload to send (max 1 MB)
     * @param array<string, string>|null $headers Optional custom headers to include
     * @param string|null $idempotencyKey Optional key to prevent duplicate sends
     *
     * @return SendResponse
     *
     * @throws HookBridgeException
     */
    public function send(
        string $endpointId,
        array $payload,
        ?array $headers = null,
        ?string $idempotencyKey = null,
    ): SendResponse {
        $body = [
            'endpoint_id' => $endpointId,
            'payload' => $payload,
        ];

        if ($headers !== null) {
            $body['headers'] = $headers;
        }

        if ($idempotencyKey !== null) {
            $body['idempotency_key'] = $idempotencyKey;
        }

        $response = $this->requestSend('POST', '/v1/webhooks/send', $body);
        $data = $response['data'];

        return new SendResponse(
            messageId: $data['message_id'],
            status: $data['status'],
        );
    }

    /**
     * Get details for a specific message.
     *
     * @param string $messageId The message ID to retrieve
     *
     * @return Message
     *
     * @throws HookBridgeException
     */
    public function getMessage(string $messageId): Message
    {
        $response = $this->request('GET', "/v1/messages/{$messageId}");
        return $this->parseMessage($response['data']);
    }

    /**
     * Replay a message for redelivery.
     *
     * @param string $messageId The message ID to replay
     *
     * @throws HookBridgeException
     */
    public function replay(string $messageId): void
    {
        $this->request('POST', "/v1/messages/{$messageId}/replay");
    }

    /**
     * Cancel a pending retry.
     *
     * @param string $messageId The message ID to cancel
     *
     * @throws HookBridgeException
     */
    public function cancelRetry(string $messageId): void
    {
        $this->request('POST', "/v1/messages/{$messageId}/cancel");
    }

    /**
     * Trigger an immediate retry for a pending message.
     *
     * @param string $messageId The message ID to retry
     *
     * @throws HookBridgeException
     */
    public function retryNow(string $messageId): void
    {
        $this->request('POST', "/v1/messages/{$messageId}/retry-now");
    }

    public function replayAllMessages(string $status, ?string $endpointId = null, ?int $limit = null): ReplayAllMessagesResponse
    {
        $params = ['status' => $status];
        if ($endpointId !== null) {
            $params['endpoint_id'] = $endpointId;
        }
        if ($limit !== null) {
            $params['limit'] = (string) $limit;
        }

        $response = $this->request('POST', '/v1/messages/replay-all?' . http_build_query($params));
        $data = $response['data'];

        return new ReplayAllMessagesResponse(
            replayed: $data['replayed'],
            failed: $data['failed'],
            stuck: $data['stuck'],
            replayedMessageIds: $data['replayed_message_ids'] ?? [],
            stuckMessageIds: $data['stuck_message_ids'] ?? [],
        );
    }

    public function replayBatchMessages(array $messageIds): ReplayBatchMessagesResponse
    {
        $response = $this->request('POST', '/v1/messages/replay-batch', ['message_ids' => $messageIds]);
        $data = $response['data'];

        return new ReplayBatchMessagesResponse(
            replayed: $data['replayed'],
            failed: $data['failed'],
            stuck: $data['stuck'],
            results: array_map(
                static fn(array $result) => new \HookBridge\Response\ReplayBatchResult(
                    messageId: $result['message_id'],
                    status: $result['status'],
                    error: $result['error'] ?? null,
                ),
                $data['results'] ?? [],
            ),
        );
    }

    /**
     * Query delivery logs with optional filters.
     *
     * @param string|null $status Filter by message status
     * @param DateTimeImmutable|string|null $startTime Filter messages created after this time
     * @param DateTimeImmutable|string|null $endTime Filter messages created before this time
     * @param int|null $limit Maximum results to return (1-500, default 50)
     * @param string|null $cursor Pagination cursor from previous response
     *
     * @return LogsResponse
     *
     * @throws HookBridgeException
     */
    public function getLogs(
        ?string $status = null,
        DateTimeImmutable|string|null $startTime = null,
        DateTimeImmutable|string|null $endTime = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): LogsResponse {
        $params = [];

        if ($status !== null) {
            $params['status'] = $status;
        }
        if ($startTime !== null) {
            $params['start_time'] = $startTime instanceof DateTimeImmutable
                ? $startTime->format('c')
                : $startTime;
        }
        if ($endTime !== null) {
            $params['end_time'] = $endTime instanceof DateTimeImmutable
                ? $endTime->format('c')
                : $endTime;
        }
        if ($limit !== null) {
            $params['limit'] = (string) $limit;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        $path = '/v1/logs';
        if (!empty($params)) {
            $path .= '?' . http_build_query($params);
        }

        $response = $this->request('GET', $path);

        return new LogsResponse(
            messages: array_map(
                fn(array $m) => $this->parseMessageSummary($m),
                $response['data']
            ),
            hasMore: $response['meta']['has_more'],
            nextCursor: $response['meta']['next_cursor'] ?? null,
        );
    }

    /**
     * Get aggregated delivery metrics.
     *
     * @param string $window Time window for aggregation (1h, 24h, 7d, 30d)
     *
     * @return Metrics
     *
     * @throws HookBridgeException
     */
    public function getMetrics(string $window = '24h'): Metrics
    {
        $response = $this->request('GET', "/v1/metrics?window={$window}");
        $data = $response['data'];

        return new Metrics(
            window: $data['window'],
            totalMessages: $data['total_messages'],
            succeeded: $data['succeeded'],
            failed: $data['failed'],
            retries: $data['retries'],
            successRate: $data['success_rate'],
            avgLatencyMs: $data['avg_latency_ms'],
        );
    }

    /**
     * Get delivery time-series metrics.
     *
     * @param string $window Time window for aggregation (1h, 24h, 7d, 30d)
     * @param string|null $endpointId Optional endpoint ID to scope the metrics
     *
     * @return TimeSeriesMetrics
     *
     * @throws HookBridgeException
     */
    public function getTimeseriesMetrics(string $window = '24h', ?string $endpointId = null): TimeSeriesMetrics
    {
        $params = ['window' => $window];
        if ($endpointId !== null) {
            $params['endpoint_id'] = $endpointId;
        }
        $data = $this->request('GET', '/v1/metrics/timeseries?' . http_build_query($params))['data'];

        return new TimeSeriesMetrics(
            window: $data['window'],
            buckets: array_map(
                static fn(array $bucket) => new TimeSeriesBucket(
                    timestamp: new DateTimeImmutable($bucket['timestamp']),
                    succeeded: $bucket['succeeded'],
                    failed: $bucket['failed'],
                    retrying: $bucket['retrying'],
                    total: $bucket['total'],
                    avgLatencyMs: $bucket['avg_latency_ms'],
                ),
                $data['buckets'] ?? [],
            ),
        );
    }

    /**
     * List messages in the Dead Letter Queue.
     *
     * @param int|null $limit Maximum results to return (1-1000, default 100)
     * @param string|null $cursor Pagination cursor from previous response
     *
     * @return DLQResponse
     *
     * @throws HookBridgeException
     */
    public function getDLQMessages(?int $limit = null, ?string $cursor = null): DLQResponse
    {
        $params = [];

        if ($limit !== null) {
            $params['limit'] = (string) $limit;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        $path = '/v1/dlq/messages';
        if (!empty($params)) {
            $path .= '?' . http_build_query($params);
        }

        $response = $this->request('GET', $path);
        $data = $response['data'];

        return new DLQResponse(
            messages: array_map(
                fn(array $m) => $this->parseMessageSummary($m),
                $data['messages'] ?? []
            ),
            hasMore: $data['has_more'],
            nextCursor: $data['next_cursor'] ?? null,
        );
    }

    /**
     * Replay a message from the Dead Letter Queue.
     *
     * @param string $messageId The message ID to replay
     *
     * @throws HookBridgeException
     */
    public function replayFromDLQ(string $messageId): void
    {
        $this->request('POST', "/v1/dlq/replay/{$messageId}");
    }

    /**
     * List all API keys for the project.
     *
     * @return APIKey[]
     *
     * @throws HookBridgeException
     */
    public function listAPIKeys(): array
    {
        $response = $this->request('GET', '/v1/api-keys');

        return array_map(
            fn(array $key) => new APIKey(
                keyId: $key['key_id'],
                prefix: $key['prefix'],
                createdAt: $key['created_at'],
                label: $key['label'] ?? null,
                lastUsedAt: $key['last_used_at'] ?? null,
            ),
            $response['data']
        );
    }

    /**
     * Create a new API key.
     *
     * @param string $mode Key mode ('live' or 'test')
     * @param string|null $label Optional human-readable label
     *
     * @return APIKeyWithSecret
     *
     * @throws HookBridgeException
     */
    public function createAPIKey(string $mode, ?string $label = null): APIKeyWithSecret
    {
        $body = ['mode' => $mode];

        if ($label !== null) {
            $body['label'] = $label;
        }

        $response = $this->request('POST', '/v1/api-keys', $body);
        $data = $response['data'];

        return new APIKeyWithSecret(
            keyId: $data['key_id'],
            key: $data['key'],
            prefix: $data['prefix'],
            createdAt: $data['created_at'],
            label: $data['label'] ?? null,
        );
    }

    /**
     * Delete an API key.
     *
     * @param string $keyId The API key ID to delete
     *
     * @throws HookBridgeException
     */
    public function deleteAPIKey(string $keyId): void
    {
        $this->request('DELETE', "/v1/api-keys/{$keyId}");
    }

    public function listProjects(): array
    {
        $response = $this->request('GET', '/v1/projects');

        return array_map(fn(array $project) => new Project(
            id: $project['id'],
            tenantId: $project['tenant_id'],
            name: $project['name'],
            status: $project['status'],
            rateLimitDefault: $project['rate_limit_default'],
            createdAt: new DateTimeImmutable($project['created_at']),
        ), $response['data']);
    }

    public function createProject(string $name, ?int $rateLimitDefault = null): Project
    {
        $body = ['name' => $name];
        if ($rateLimitDefault !== null) {
            $body['rate_limit_default'] = $rateLimitDefault;
        }
        $data = $this->request('POST', '/v1/projects', $body)['data'];

        return new Project(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            name: $data['name'],
            status: $data['status'],
            rateLimitDefault: $data['rate_limit_default'],
            createdAt: new DateTimeImmutable($data['created_at']),
        );
    }

    public function updateProject(string $projectId, ?string $name = null, ?int $rateLimitDefault = null): Project
    {
        $body = [];
        if ($name !== null) {
            $body['name'] = $name;
        }
        if ($rateLimitDefault !== null) {
            $body['rate_limit_default'] = $rateLimitDefault;
        }
        $data = $this->request('PUT', "/v1/projects/{$projectId}", $body)['data'];

        return new Project(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            name: $data['name'],
            status: $data['status'],
            rateLimitDefault: $data['rate_limit_default'],
            createdAt: new DateTimeImmutable($data['created_at']),
        );
    }

    public function deleteProject(string $projectId): void
    {
        $this->request('DELETE', "/v1/projects/{$projectId}");
    }

    /**
     * Create a new webhook endpoint.
     *
     * @param string $url HTTPS URL of the webhook endpoint (must be publicly accessible)
     * @param string|null $description Optional description of the endpoint
     * @param bool $hmacEnabled Whether to sign webhooks with HMAC-SHA256 (default: true)
     * @param int|null $rateLimitRps Rate limit in requests per second (0 = no limit)
     * @param int|null $burst Maximum burst size for rate limiting
     * @param array<string, string>|null $headers Custom headers to include in webhook requests
     *
     * @return CreateEndpointResponse The created endpoint with signing_secret (shown only once!)
     *
     * @throws HookBridgeException
     */
    public function createEndpoint(
        string $url,
        ?string $description = null,
        bool $hmacEnabled = true,
        ?int $rateLimitRps = null,
        ?int $burst = null,
        ?array $headers = null,
    ): CreateEndpointResponse {
        $body = [
            'url' => $url,
            'hmac_enabled' => $hmacEnabled,
        ];

        if ($description !== null) {
            $body['description'] = $description;
        }
        if ($rateLimitRps !== null && $rateLimitRps > 0) {
            $body['rate_limit_rps'] = $rateLimitRps;
        }
        if ($burst !== null && $burst > 0) {
            $body['burst'] = $burst;
        }
        if ($headers !== null) {
            $body['headers'] = $headers;
        }

        $response = $this->request('POST', '/v1/endpoints', $body);
        $data = $response['data'];

        return new CreateEndpointResponse(
            id: $data['id'],
            url: $data['url'],
            signingSecret: $data['signing_secret'],
            description: $data['description'] ?? null,
            createdAt: new DateTimeImmutable($data['created_at']),
        );
    }

    /**
     * Get details for a specific endpoint.
     *
     * @param string $endpointId The endpoint ID to retrieve
     *
     * @return Endpoint
     *
     * @throws HookBridgeException
     */
    public function getEndpoint(string $endpointId): Endpoint
    {
        $response = $this->request('GET', "/v1/endpoints/{$endpointId}");
        $data = $response['data'];

        return new Endpoint(
            id: $data['id'],
            url: $data['url'],
            description: $data['description'] ?? null,
            hmacEnabled: $data['hmac_enabled'] ?? true,
            rateLimitRps: $data['rate_limit_rps'] ?? null,
            burst: $data['burst'] ?? null,
            headers: $data['headers'] ?? null,
            paused: $data['paused'],
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: new DateTimeImmutable($data['updated_at']),
        );
    }

    /**
     * List all endpoints for the project.
     *
     * @param int|null $limit Maximum results to return (1-100, default 50)
     * @param string|null $cursor Pagination cursor from previous response
     *
     * @return ListEndpointsResponse
     *
     * @throws HookBridgeException
     */
    public function listEndpoints(?int $limit = null, ?string $cursor = null): ListEndpointsResponse
    {
        $params = [];

        if ($limit !== null) {
            $params['limit'] = (string) $limit;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        $path = '/v1/endpoints';
        if (!empty($params)) {
            $path .= '?' . http_build_query($params);
        }

        $response = $this->request('GET', $path);

        return new ListEndpointsResponse(
            endpoints: array_map(
                fn(array $e) => new EndpointSummary(
                    id: $e['id'],
                    url: $e['url'],
                    description: $e['description'] ?? null,
                    paused: $e['paused'],
                    createdAt: new DateTimeImmutable($e['created_at']),
                ),
                $response['data']
            ),
            hasMore: isset($response['meta']['next_cursor']),
            nextCursor: $response['meta']['next_cursor'] ?? null,
        );
    }

    /**
     * Update an existing endpoint.
     *
     * @param string $endpointId The endpoint ID to update
     * @param string|null $url New HTTPS URL (optional)
     * @param string|null $description New description (optional)
     * @param bool|null $hmacEnabled Whether to sign webhooks (optional)
     * @param int|null $rateLimitRps New rate limit (optional)
     * @param int|null $burst New burst size (optional)
     * @param array<string, string>|null $headers New custom headers (optional)
     *
     * @return Endpoint The updated endpoint
     *
     * @throws HookBridgeException
     */
    public function updateEndpoint(
        string $endpointId,
        ?string $url = null,
        ?string $description = null,
        ?bool $hmacEnabled = null,
        ?int $rateLimitRps = null,
        ?int $burst = null,
        ?array $headers = null,
    ): Endpoint {
        $body = [];

        if ($url !== null) {
            $body['url'] = $url;
        }
        if ($description !== null) {
            $body['description'] = $description;
        }
        if ($hmacEnabled !== null) {
            $body['hmac_enabled'] = $hmacEnabled;
        }
        if ($rateLimitRps !== null) {
            $body['rate_limit_rps'] = $rateLimitRps;
        }
        if ($burst !== null) {
            $body['burst'] = $burst;
        }
        if ($headers !== null) {
            $body['headers'] = $headers;
        }

        $response = $this->request('PATCH', "/v1/endpoints/{$endpointId}", $body);
        $data = $response['data'];

        return new Endpoint(
            id: $data['id'],
            url: $data['url'],
            description: $data['description'] ?? null,
            hmacEnabled: $data['hmac_enabled'] ?? true,
            rateLimitRps: $data['rate_limit_rps'] ?? null,
            burst: $data['burst'] ?? null,
            headers: $data['headers'] ?? null,
            paused: $data['paused'],
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: new DateTimeImmutable($data['updated_at']),
        );
    }

    /**
     * Delete an endpoint (soft-delete).
     *
     * The endpoint will no longer accept new messages, but existing messages
     * will continue to be delivered.
     *
     * @param string $endpointId The endpoint ID to delete
     *
     * @throws HookBridgeException
     */
    public function deleteEndpoint(string $endpointId): void
    {
        $this->request('DELETE', "/v1/endpoints/{$endpointId}");
    }

    public function pauseEndpoint(string $endpointId): PauseState
    {
        $data = $this->request('POST', "/v1/endpoints/{$endpointId}/pause")['data'];
        return new PauseState(id: $data['id'], paused: $data['paused']);
    }

    public function resumeEndpoint(string $endpointId): PauseState
    {
        $data = $this->request('POST', "/v1/endpoints/{$endpointId}/resume")['data'];
        return new PauseState(
            id: $data['id'],
            paused: $data['paused'],
            messagesRequeued: $data['messages_requeued'] ?? null,
        );
    }

    /**
     * Rotate the signing secret for an endpoint.
     *
     * The old secret is immediately invalidated. Use this if your secret
     * has been compromised.
     *
     * @param string $endpointId The endpoint ID to rotate the secret for
     *
     * @return RotateSecretResponse The new signing_secret (shown only once!)
     *
     * @throws HookBridgeException
     */
    public function createEndpointSigningKey(string $endpointId): RotateSecretResponse
    {
        $response = $this->request('POST', "/v1/endpoints/{$endpointId}/signing-keys");
        $data = $response['data'];

        return new RotateSecretResponse(
            id: $data['id'],
            signingSecret: $data['signing_secret'],
        );
    }

    public function listEndpointSigningKeys(string $endpointId): array
    {
        $response = $this->request('GET', "/v1/endpoints/{$endpointId}/signing-keys");

        return array_map(fn(array $key) => new SigningKey(
            id: $key['id'],
            keyHint: $key['key_hint'],
            createdAt: new DateTimeImmutable($key['created_at']),
        ), $response['data']);
    }

    public function deleteEndpointSigningKey(string $endpointId, string $keyId): void
    {
        $this->request('DELETE', "/v1/endpoints/{$endpointId}/signing-keys/{$keyId}");
    }

    public function rotateEndpointSecret(string $endpointId): RotateSecretResponse
    {
        return $this->createEndpointSigningKey($endpointId);
    }

    public function createCheckout(string $plan, string $interval): CheckoutSession
    {
        $data = $this->request('POST', '/v1/billing/checkout', ['plan' => $plan, 'interval' => $interval])['data'];

        return new CheckoutSession(
            sessionId: $data['session_id'],
            checkoutUrl: $data['checkout_url'],
        );
    }

    public function createPortal(?string $returnUrl = null): PortalSession
    {
        $body = [];
        if ($returnUrl !== null) {
            $body['return_url'] = $returnUrl;
        }
        $data = $this->request('POST', '/v1/billing/portal', $body)['data'];

        return new PortalSession(portalUrl: $data['portal_url']);
    }

    public function getUsageHistory(int $limit = 12, int $offset = 0): UsageHistoryResponse
    {
        $response = $this->request('GET', '/v1/billing/usage-history?' . http_build_query(['limit' => $limit, 'offset' => $offset]));

        return new UsageHistoryResponse(
            rows: array_map(
                static fn(array $row) => new \HookBridge\Response\UsageHistoryRow(
                    periodStart: $row['period_start'],
                    periodEnd: $row['period_end'],
                    messageCount: $row['message_count'],
                    overageCount: $row['overage_count'],
                    planLimit: $row['plan_limit'] ?? null,
                ),
                $response['data']
            ),
            total: $response['meta']['total'],
            limit: $response['meta']['limit'],
            offset: $response['meta']['offset'],
            hasMore: $response['meta']['has_more'],
        );
    }

    public function getInvoices(int $limit = 12, ?string $startingAfter = null): InvoicesResponse
    {
        $params = ['limit' => $limit];
        if ($startingAfter !== null) {
            $params['starting_after'] = $startingAfter;
        }
        $response = $this->request('GET', '/v1/billing/invoices?' . http_build_query($params));

        return new InvoicesResponse(
            invoices: array_map(
                static fn(array $invoice) => new \HookBridge\Response\Invoice(
                    id: $invoice['id'],
                    status: $invoice['status'],
                    amountDue: $invoice['amount_due'],
                    amountPaid: $invoice['amount_paid'],
                    currency: $invoice['currency'],
                    periodStart: new DateTimeImmutable($invoice['period_start']),
                    periodEnd: new DateTimeImmutable($invoice['period_end']),
                    created: new DateTimeImmutable($invoice['created']),
                    lines: array_map(
                        static fn(array $line) => new \HookBridge\Response\InvoiceLine(
                            description: $line['description'],
                            amount: $line['amount'],
                            quantity: $line['quantity'],
                        ),
                        $invoice['lines'] ?? [],
                    ),
                    invoicePdf: $invoice['invoice_pdf'] ?? null,
                    hostedInvoiceUrl: $invoice['hosted_invoice_url'] ?? null,
                ),
                $response['data']
            ),
            hasMore: $response['meta']['has_more'],
        );
    }

    public function createInboundEndpoint(
        string $url,
        ?string $name = null,
        ?string $description = null,
        ?bool $verifyStaticToken = null,
        ?string $tokenHeaderName = null,
        ?string $tokenQueryParam = null,
        ?string $tokenValue = null,
        ?bool $verifyHmac = null,
        ?string $hmacHeaderName = null,
        ?string $hmacSecret = null,
        ?string $timestampHeaderName = null,
        ?int $timestampTtlSeconds = null,
        ?bool $verifyIpAllowlist = null,
        ?array $allowedCidrs = null,
        ?array $idempotencyHeaderNames = null,
        ?int $ingestResponseCode = null,
        ?bool $signingEnabled = null,
    ): InboundEndpointCreatedResponse {
        $body = array_filter([
            'url' => $url,
            'name' => $name,
            'description' => $description,
            'verify_static_token' => $verifyStaticToken,
            'token_header_name' => $tokenHeaderName,
            'token_query_param' => $tokenQueryParam,
            'token_value' => $tokenValue,
            'verify_hmac' => $verifyHmac,
            'hmac_header_name' => $hmacHeaderName,
            'hmac_secret' => $hmacSecret,
            'timestamp_header_name' => $timestampHeaderName,
            'timestamp_ttl_seconds' => $timestampTtlSeconds,
            'verify_ip_allowlist' => $verifyIpAllowlist,
            'allowed_cidrs' => $allowedCidrs,
            'idempotency_header_names' => $idempotencyHeaderNames,
            'ingest_response_code' => $ingestResponseCode,
            'signing_enabled' => $signingEnabled,
        ], static fn($value) => $value !== null);

        $data = $this->request('POST', '/v1/inbound-endpoints', $body)['data'];

        return new InboundEndpointCreatedResponse(
            id: $data['id'],
            name: $data['name'],
            url: $data['url'],
            ingestUrl: $data['ingest_url'],
            secretToken: $data['secret_token'],
            createdAt: new DateTimeImmutable($data['created_at']),
        );
    }

    public function listInboundEndpoints(?int $limit = null, ?string $cursor = null): ListInboundEndpointsResponse
    {
        $params = [];
        if ($limit !== null) {
            $params['limit'] = (string) $limit;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }
        $path = '/v1/inbound-endpoints' . (!empty($params) ? '?' . http_build_query($params) : '');
        $response = $this->request('GET', $path);

        return new ListInboundEndpointsResponse(
            endpoints: array_map(
                static fn(array $endpoint) => new InboundEndpointSummary(
                    id: $endpoint['id'],
                    name: $endpoint['name'],
                    url: $endpoint['url'],
                    active: $endpoint['active'],
                    paused: $endpoint['paused'],
                    createdAt: new DateTimeImmutable($endpoint['created_at']),
                ),
                $response['data']
            ),
            hasMore: isset($response['meta']['next_cursor']),
            nextCursor: $response['meta']['next_cursor'] ?? null,
        );
    }

    public function getInboundEndpoint(string $endpointId): InboundEndpoint
    {
        $data = $this->request('GET', "/v1/inbound-endpoints/{$endpointId}")['data'];

        return new InboundEndpoint(
            id: $data['id'],
            name: $data['name'],
            url: $data['url'],
            active: $data['active'],
            paused: $data['paused'],
            verifyStaticToken: $data['verify_static_token'],
            verifyHmac: $data['verify_hmac'],
            verifyIpAllowlist: $data['verify_ip_allowlist'],
            ingestResponseCode: $data['ingest_response_code'],
            idempotencyHeaderNames: $data['idempotency_header_names'] ?? [],
            signingEnabled: $data['signing_enabled'],
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: new DateTimeImmutable($data['updated_at']),
            description: $data['description'] ?? null,
        );
    }

    public function updateInboundEndpoint(string $endpointId, array $attributes): UpdateResult
    {
        $data = $this->request('PATCH', "/v1/inbound-endpoints/{$endpointId}", $attributes)['data'];
        return new UpdateResult(id: $data['id'], updated: $data['updated']);
    }

    public function deleteInboundEndpoint(string $endpointId): DeleteResult
    {
        $data = $this->request('DELETE', "/v1/inbound-endpoints/{$endpointId}")['data'];
        return new DeleteResult(deleted: $data['deleted'], id: $data['id'] ?? null);
    }

    public function pauseInboundEndpoint(string $endpointId): PauseState
    {
        $data = $this->request('POST', "/v1/inbound-endpoints/{$endpointId}/pause")['data'];
        return new PauseState(id: $data['id'], paused: $data['paused']);
    }

    public function resumeInboundEndpoint(string $endpointId): PauseState
    {
        $data = $this->request('POST', "/v1/inbound-endpoints/{$endpointId}/resume")['data'];
        return new PauseState(id: $data['id'], paused: $data['paused']);
    }

    public function replayInboundMessage(string $messageId): SendResponse
    {
        $data = $this->request('POST', "/v1/inbound-messages/{$messageId}/replay")['data'];
        return new SendResponse(messageId: $data['message_id'], status: $data['status']);
    }

    public function replayAllInboundMessages(string $status, ?string $inboundEndpointId = null, ?int $limit = null): ReplayAllMessagesResponse
    {
        $params = ['status' => $status];
        if ($inboundEndpointId !== null) {
            $params['inbound_endpoint_id'] = $inboundEndpointId;
        }
        if ($limit !== null) {
            $params['limit'] = (string) $limit;
        }
        $data = $this->request('POST', '/v1/inbound-messages/replay-all?' . http_build_query($params))['data'];

        return new ReplayAllMessagesResponse(
            replayed: $data['replayed'],
            failed: $data['failed'],
            stuck: $data['stuck'],
            replayedMessageIds: $data['replayed_message_ids'] ?? [],
            stuckMessageIds: $data['stuck_message_ids'] ?? [],
        );
    }

    public function replayBatchInboundMessages(array $messageIds): ReplayBatchMessagesResponse
    {
        $data = $this->request('POST', '/v1/inbound-messages/replay-batch', ['message_ids' => $messageIds])['data'];

        return new ReplayBatchMessagesResponse(
            replayed: $data['replayed'],
            failed: $data['failed'],
            stuck: $data['stuck'],
            results: array_map(
                static fn(array $result) => new \HookBridge\Response\ReplayBatchResult(
                    messageId: $result['message_id'],
                    status: $result['status'],
                    error: $result['error'] ?? null,
                ),
                $data['results'] ?? [],
            ),
        );
    }

    public function getInboundLogs(?string $status = null, ?string $inboundEndpointId = null, DateTimeImmutable|string|null $startTime = null, DateTimeImmutable|string|null $endTime = null, ?int $limit = null, ?string $cursor = null): InboundLogsResponse
    {
        $params = [];
        if ($status !== null) {
            $params['status'] = $status;
        }
        if ($inboundEndpointId !== null) {
            $params['inbound_endpoint_id'] = $inboundEndpointId;
        }
        if ($startTime !== null) {
            $params['start_time'] = $startTime instanceof DateTimeImmutable ? $startTime->format('c') : $startTime;
        }
        if ($endTime !== null) {
            $params['end_time'] = $endTime instanceof DateTimeImmutable ? $endTime->format('c') : $endTime;
        }
        if ($limit !== null) {
            $params['limit'] = (string) $limit;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }
        $response = $this->request('GET', '/v1/inbound-logs' . (!empty($params) ? '?' . http_build_query($params) : ''));

        return new InboundLogsResponse(
            entries: array_map(
                static fn(array $entry) => new \HookBridge\Response\InboundLogEntry(
                    messageId: $entry['message_id'],
                    inboundEndpointId: $entry['inbound_endpoint_id'],
                    endpoint: $entry['endpoint'],
                    status: $entry['status'],
                    attemptCount: $entry['attempt_count'],
                    receivedAt: new DateTimeImmutable($entry['received_at']),
                    deliveredAt: isset($entry['delivered_at']) ? new DateTimeImmutable($entry['delivered_at']) : null,
                    responseStatus: $entry['response_status'] ?? null,
                    responseLatencyMs: $entry['response_latency_ms'] ?? null,
                    lastError: $entry['last_error'] ?? null,
                    totalDeliveryMs: $entry['total_delivery_ms'] ?? null,
                ),
                $response['data']
            ),
            hasMore: $response['meta']['has_more'],
            nextCursor: $response['meta']['next_cursor'] ?? null,
        );
    }

    public function getInboundMetrics(string $window = '24h', ?string $inboundEndpointId = null): InboundMetrics
    {
        $params = ['window' => $window];
        if ($inboundEndpointId !== null) {
            $params['inbound_endpoint_id'] = $inboundEndpointId;
        }
        $data = $this->request('GET', '/v1/inbound-metrics?' . http_build_query($params))['data'];

        return new InboundMetrics(
            window: $data['window'],
            totalMessages: $data['total_messages'],
            succeeded: $data['succeeded'],
            failed: $data['failed'],
            retries: $data['retries'],
            successRate: $data['success_rate'],
            avgLatencyMs: $data['avg_latency_ms'],
            avgDeliveryTimeMs: $data['avg_delivery_time_ms'],
        );
    }

    public function getInboundTimeseriesMetrics(string $window = '24h', ?string $inboundEndpointId = null): TimeSeriesMetrics
    {
        $params = ['window' => $window];
        if ($inboundEndpointId !== null) {
            $params['inbound_endpoint_id'] = $inboundEndpointId;
        }
        $data = $this->request('GET', '/v1/inbound-metrics/timeseries?' . http_build_query($params))['data'];

        return new TimeSeriesMetrics(
            window: $data['window'],
            buckets: array_map(
                static fn(array $bucket) => new TimeSeriesBucket(
                    timestamp: new DateTimeImmutable($bucket['timestamp']),
                    succeeded: $bucket['succeeded'],
                    failed: $bucket['failed'],
                    retrying: $bucket['retrying'],
                    total: $bucket['total'],
                    avgLatencyMs: $bucket['avg_latency_ms'],
                ),
                $data['buckets']
            ),
        );
    }

    public function listInboundRejections(?string $inboundEndpointId = null, DateTimeImmutable|string|null $startTime = null, DateTimeImmutable|string|null $endTime = null, ?int $limit = null, ?string $cursor = null): InboundRejectionsResponse
    {
        $params = [];
        if ($inboundEndpointId !== null) {
            $params['inbound_endpoint_id'] = $inboundEndpointId;
        }
        if ($startTime !== null) {
            $params['start_time'] = $startTime instanceof DateTimeImmutable ? $startTime->format('c') : $startTime;
        }
        if ($endTime !== null) {
            $params['end_time'] = $endTime instanceof DateTimeImmutable ? $endTime->format('c') : $endTime;
        }
        if ($limit !== null) {
            $params['limit'] = (string) $limit;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }
        $response = $this->request('GET', '/v1/inbound-rejections' . (!empty($params) ? '?' . http_build_query($params) : ''));

        return new InboundRejectionsResponse(
            entries: array_map(
                static fn(array $entry) => new InboundRejection(
                    id: $entry['id'],
                    reasonCode: $entry['reason_code'],
                    receivedAt: new DateTimeImmutable($entry['received_at']),
                    inboundEndpointId: $entry['inbound_endpoint_id'] ?? null,
                    reasonDetail: $entry['reason_detail'] ?? null,
                    sourceIp: $entry['source_ip'] ?? null,
                    headersRedacted: $entry['headers_redacted'] ?? null,
                ),
                $response['data']
            ),
            hasMore: $response['meta']['has_more'],
            nextCursor: $response['meta']['next_cursor'] ?? null,
        );
    }

    public function createExport(string|DateTimeImmutable $startTime, string|DateTimeImmutable $endTime, ?string $status = null, ?string $endpointId = null): ExportRecord
    {
        $body = [
            'start_time' => $startTime instanceof DateTimeImmutable ? $startTime->format('c') : $startTime,
            'end_time' => $endTime instanceof DateTimeImmutable ? $endTime->format('c') : $endTime,
        ];
        if ($status !== null) {
            $body['status'] = $status;
        }
        if ($endpointId !== null) {
            $body['endpoint_id'] = $endpointId;
        }
        return $this->parseExportRecord($this->request('POST', '/v1/exports', $body)['data']);
    }

    public function listExports(): array
    {
        $response = $this->request('GET', '/v1/exports');
        return array_map(fn(array $export) => $this->parseExportRecord($export), $response['data']);
    }

    public function getExport(string $exportId): ExportRecord
    {
        return $this->parseExportRecord($this->request('GET', "/v1/exports/{$exportId}")['data']);
    }

    public function downloadExport(string $exportId): string
    {
        return $this->requestWithClient($this->client, 'GET', "/v1/exports/{$exportId}/download", null, true)['location'];
    }

    /**
     * Make an HTTP request to the management API with retries.
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param array<string, mixed>|null $body Request body
     *
     * @return array<string, mixed>
     *
     * @throws HookBridgeException
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        return $this->requestWithClient($this->client, $method, $path, $body);
    }

    /**
     * Make an HTTP request to the send API with retries.
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param array<string, mixed>|null $body Request body
     *
     * @return array<string, mixed>
     *
     * @throws HookBridgeException
     */
    private function requestSend(string $method, string $path, ?array $body = null): array
    {
        return $this->requestWithClient($this->sendClient, $method, $path, $body);
    }

    /**
     * Make an HTTP request with retries using the specified client.
     *
     * @param Client $httpClient The Guzzle client to use
     * @param string $method HTTP method
     * @param string $path Request path
     * @param array<string, mixed>|null $body Request body
     *
     * @return array<string, mixed>
     *
     * @throws HookBridgeException
     */
    private function requestWithClient(Client $httpClient, string $method, string $path, ?array $body = null, bool $allowRedirect = false): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            try {
                $options = ['allow_redirects' => !$allowRedirect];
                if ($body !== null) {
                    $options['json'] = $body;
                }

                $response = $httpClient->request($method, $path, $options);
                if ($allowRedirect && $response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
                    return ['location' => $response->getHeaderLine('Location')];
                }
                $content = $response->getBody()->getContents();

                if (empty($content)) {
                    return [];
                }

                return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (RequestException $e) {
                $response = $e->getResponse();

                if ($response !== null) {
                    $statusCode = $response->getStatusCode();

                    // Don't retry client errors (4xx)
                    if ($statusCode >= 400 && $statusCode < 500) {
                        $this->handleErrorResponse($response);
                    }
                }

                $lastException = new HookBridgeException($e->getMessage());
            } catch (ConnectException $e) {
                $lastException = new HookBridgeException("Network error: {$e->getMessage()}");
            }

            // Wait before retrying
            if ($attempt < $this->retries) {
                usleep((int) (pow(2, $attempt) * 100000)); // 100ms, 200ms, 400ms...
            }
        }

        throw $lastException ?? new HookBridgeException('Request failed');
    }

    private function parseExportRecord(array $data): ExportRecord
    {
        return new ExportRecord(
            id: $data['id'],
            projectId: $data['project_id'],
            status: $data['status'],
            filterStartTime: new DateTimeImmutable($data['filter_start_time']),
            filterEndTime: new DateTimeImmutable($data['filter_end_time']),
            createdAt: new DateTimeImmutable($data['created_at']),
            filterStatus: $data['filter_status'] ?? null,
            filterEndpointId: $data['filter_endpoint_id'] ?? null,
            rowCount: $data['row_count'] ?? null,
            fileSizeBytes: $data['file_size_bytes'] ?? null,
            errorMessage: $data['error_message'] ?? null,
            startedAt: isset($data['started_at']) ? new DateTimeImmutable($data['started_at']) : null,
            completedAt: isset($data['completed_at']) ? new DateTimeImmutable($data['completed_at']) : null,
            expiresAt: isset($data['expires_at']) ? new DateTimeImmutable($data['expires_at']) : null,
        );
    }

    /**
     * Handle error responses from the API.
     *
     * @throws HookBridgeException
     */
    private function handleErrorResponse(\Psr\Http\Message\ResponseInterface $response): never
    {
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $code = $data['error']['code'] ?? 'UNKNOWN_ERROR';
            $message = $data['error']['message'] ?? "HTTP {$statusCode}";
            $requestId = $data['meta']['request_id'] ?? null;
        } catch (\JsonException) {
            $code = 'UNKNOWN_ERROR';
            $message = "HTTP {$statusCode}";
            $requestId = null;
        }

        throw match ($statusCode) {
            401 => new AuthenticationException($message, $requestId),
            404 => new NotFoundException($message, $requestId),
            400 => new ValidationException($message, $requestId),
            409 => $code === 'IDEMPOTENCY_MISMATCH'
                ? new IdempotencyException($message, $requestId)
                : new HookBridgeException($message, $code, $requestId, $statusCode),
            429 => $code === 'REPLAY_LIMIT_EXCEEDED'
                ? new ReplayLimitException($message, $requestId)
                : new RateLimitException(
                    $message,
                    $requestId,
                    isset($response->getHeader('Retry-After')[0])
                        ? (int) $response->getHeader('Retry-After')[0]
                        : null
                ),
            default => new HookBridgeException($message, $code, $requestId, $statusCode),
        };
    }

    /**
     * Parse a message from API response.
     */
    private function parseMessage(array $data): Message
    {
        return new Message(
            id: $data['id'],
            projectId: $data['project_id'],
            endpointId: $data['endpoint_id'],
            status: $data['status'],
            attemptCount: $data['attempt_count'],
            replayCount: $data['replay_count'],
            contentType: $data['content_type'],
            sizeBytes: $data['size_bytes'],
            payloadSha256: $data['payload_sha256'],
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: new DateTimeImmutable($data['updated_at']),
            idempotencyKey: $data['idempotency_key'] ?? null,
            nextAttemptAt: isset($data['next_attempt_at'])
                ? new DateTimeImmutable($data['next_attempt_at'])
                : null,
            lastError: $data['last_error'] ?? null,
            responseStatus: $data['response_status'] ?? null,
            responseLatencyMs: $data['response_latency_ms'] ?? null,
        );
    }

    /**
     * Parse a message summary from API response.
     */
    private function parseMessageSummary(array $data): MessageSummary
    {
        return new MessageSummary(
            messageId: $data['message_id'],
            endpoint: $data['endpoint'],
            status: $data['status'],
            attemptCount: $data['attempt_count'],
            createdAt: $data['created_at'],
            deliveredAt: $data['delivered_at'] ?? null,
            responseStatus: $data['response_status'] ?? null,
            responseLatencyMs: $data['response_latency_ms'] ?? null,
            lastError: $data['last_error'] ?? null,
        );
    }
}
