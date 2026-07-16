<?php

declare(strict_types=1);

namespace HookBridge\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use HookBridge\HookBridge;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SpecParityTest extends TestCase
{
    private function makeClient(array $managementResponses, array $sendResponses = []): HookBridge
    {
        $client = new HookBridge('hb_test_mock_key');

        $management = new Client([
            'base_uri' => 'https://api.hookbridge.io',
            'handler' => HandlerStack::create(new MockHandler($managementResponses)),
        ]);

        $send = new Client([
            'base_uri' => 'https://send.hookbridge.io',
            'handler' => HandlerStack::create(new MockHandler($sendResponses)),
        ]);

        $reflection = new ReflectionClass($client);
        foreach (['client' => $management, 'sendClient' => $send] as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setValue($client, $value);
        }

        return $client;
    }

    private function makeClientWithHistory(array $managementResponses, array &$history): HookBridge
    {
        $client = new HookBridge('hb_test_mock_key');

        $managementHandler = HandlerStack::create(new MockHandler($managementResponses));
        $managementHandler->push(Middleware::history($history));
        $management = new Client([
            'base_uri' => 'https://api.hookbridge.io',
            'handler' => $managementHandler,
        ]);

        $send = new Client([
            'base_uri' => 'https://send.hookbridge.io',
            'handler' => HandlerStack::create(new MockHandler([])),
        ]);

        $reflection = new ReflectionClass($client);
        foreach (['client' => $management, 'sendClient' => $send] as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setValue($client, $value);
        }

        return $client;
    }

    private function makeClientWithRequestHistory(array $managementResponses, array $sendResponses, array &$managementHistory, array &$sendHistory): HookBridge
    {
        $client = new HookBridge('hb_test_mock_key');

        $managementHandler = HandlerStack::create(new MockHandler($managementResponses));
        $managementHandler->push(Middleware::history($managementHistory));
        $management = new Client([
            'base_uri' => 'https://api.hookbridge.io',
            'handler' => $managementHandler,
        ]);

        $sendHandler = HandlerStack::create(new MockHandler($sendResponses));
        $sendHandler->push(Middleware::history($sendHistory));
        $send = new Client([
            'base_uri' => 'https://send.hookbridge.io',
            'handler' => $sendHandler,
        ]);

        $reflection = new ReflectionClass($client);
        foreach (['client' => $management, 'sendClient' => $send] as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setValue($client, $value);
        }

        return $client;
    }

    private function jsonResponse(array $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, array_merge(['Content-Type' => 'application/json'], $headers), json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function assertRequest(array $history, int $index, string $method, string $path, array $query = [], ?array $jsonBody = null): void
    {
        $request = $history[$index]['request'];

        self::assertSame($method, $request->getMethod());
        self::assertSame($path, $request->getUri()->getPath());

        parse_str($request->getUri()->getQuery(), $actualQuery);
        self::assertSame($query, $actualQuery);

        if ($jsonBody !== null) {
            self::assertSame($jsonBody, json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testSpecParitySurface(): void
    {
        $client = $this->makeClient([
            $this->jsonResponse(['data' => ['replayed' => 2, 'failed' => 1, 'stuck' => 0, 'replayed_message_ids' => ['01935abc-def0-7123-4567-890abcdef013'], 'stuck_message_ids' => []]]),
            $this->jsonResponse(['data' => ['replayed' => 1, 'failed' => 1, 'stuck' => 0, 'results' => [['message_id' => '01935abc-def0-7123-4567-890abcdef013', 'status' => 'replayed'], ['message_id' => '01935abc-def0-7123-4567-890abcdef014', 'status' => 'failed', 'error' => 'message not replayable']]]]),
            $this->jsonResponse(['data' => ['id' => 'sk_550e8400e29b41d4a716446655440001', 'signing_secret' => 'whsec_new_secret_12345', 'key_hint' => '1234', 'created_at' => '2025-01-01T00:00:00Z']]),
            $this->jsonResponse(['data' => [['id' => 'sk_550e8400e29b41d4a716446655440001', 'key_hint' => '1234', 'created_at' => '2025-01-01T00:00:00Z']]]),
            new Response(204),
            $this->jsonResponse(['data' => ['window' => '24h', 'buckets' => [['timestamp' => '2025-12-06T00:00:00Z', 'succeeded' => 10, 'failed' => 1, 'retrying' => 2, 'total' => 13, 'avg_latency_ms' => 180]]]]),
            $this->jsonResponse(['data' => ['plan' => 'starter', 'status' => 'active', 'limits' => ['plan' => 'starter', 'messages_per_month' => 5000, 'max_projects' => 3, 'max_endpoints' => 25, 'retention_days' => 30], 'usage' => ['messages_used' => 123, 'period_start' => '2026-02-01T00:00:00Z', 'period_end' => '2026-02-28T23:59:59Z'], 'cancel_at_period_end' => false, 'current_period_end' => '2026-03-01T00:00:00Z']]),
            $this->jsonResponse(['data' => [['period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'message_count' => 6102, 'overage_count' => 1102, 'plan_limit' => 5000]], 'meta' => ['total' => 6, 'limit' => 12, 'offset' => 0, 'has_more' => false]]),
            $this->jsonResponse(['data' => [['id' => 'in_1abc', 'status' => 'paid', 'amount_due' => 1000, 'amount_paid' => 1000, 'currency' => 'usd', 'period_start' => '2026-02-05T00:00:00Z', 'period_end' => '2026-03-05T00:00:00Z', 'created' => '2026-03-05T06:00:00Z', 'invoice_pdf' => 'https://pay.stripe.com/invoice/abc', 'hosted_invoice_url' => 'https://invoice.stripe.com/abc', 'lines' => [['description' => 'Starter Plan (Monthly)', 'amount' => 1000, 'quantity' => 1]]]], 'meta' => ['has_more' => false]]),
        ]);

        $replayAll = $client->replayAllMessages('failed_permanent', 'ep_550e8400e29b41d4a716446655440000', 50);
        $replayBatch = $client->replayBatchMessages(['01935abc-def0-7123-4567-890abcdef013', '01935abc-def0-7123-4567-890abcdef014']);
        $createdSigningKey = $client->createEndpointSigningKey('ep_550e8400e29b41d4a716446655440000');
        $signingKeys = $client->listEndpointSigningKeys('ep_550e8400e29b41d4a716446655440000');
        $client->deleteEndpointSigningKey('ep_550e8400e29b41d4a716446655440000', 'sk_550e8400e29b41d4a716446655440001');
        $timeseries = $client->getTimeseriesMetrics(endpointId: 'ep_550e8400e29b41d4a716446655440000');
        $subscription = $client->getSubscription();
        $usage = $client->getUsageHistory();
        $invoices = $client->getInvoices();

        self::assertSame(2, $replayAll->replayed);
        self::assertSame('message not replayable', $replayBatch->results[1]->error);
        self::assertSame('whsec_new_secret_12345', $createdSigningKey->signingSecret);
        self::assertSame('sk_550e8400e29b41d4a716446655440001', $signingKeys[0]->id);
        self::assertSame(1, $timeseries->buckets[0]->failed);
        self::assertSame('starter', $subscription->plan);
        self::assertSame(123, $subscription->usage->messagesUsed);
        self::assertSame(6102, $usage->rows[0]->messageCount);
        self::assertSame(1, $invoices->invoices[0]->lines[0]->quantity);
    }

    public function testSessionOnlyMethodsRemoved(): void
    {
        foreach (['listProjects', 'createProject', 'getProject', 'updateProject', 'deleteProject', 'createCheckout', 'createPortal'] as $method) {
            self::assertFalse(method_exists(HookBridge::class, $method), "{$method} should be removed");
        }

        foreach (['getSubscription', 'getUsageHistory', 'getInvoices'] as $method) {
            self::assertTrue(method_exists(HookBridge::class, $method), "{$method} should still exist");
        }
    }

    public function testCreateExportSerializesDateTimeImmutable(): void
    {
        $history = [];
        $client = $this->makeClientWithHistory([
            $this->jsonResponse([
                'data' => [
                    'id' => '01935abc-def0-7123-4567-890abcdef077',
                    'project_id' => 'proj_abc123',
                    'status' => 'pending',
                    'filter_start_time' => '2025-12-01T00:00:00+00:00',
                    'filter_end_time' => '2025-12-06T23:59:59+00:00',
                    'created_at' => '2025-12-06T12:00:00Z',
                ],
                'meta' => ['request_id' => 'req-12345'],
            ]),
        ], $history);

        $export = $client->createExport(
            new \DateTimeImmutable('2025-12-01T00:00:00+00:00'),
            new \DateTimeImmutable('2025-12-06T23:59:59+00:00'),
            endpointId: 'ep_550e8400e29b41d4a716446655440000',
        );

        self::assertSame('01935abc-def0-7123-4567-890abcdef077', $export->id);
        self::assertCount(1, $history);
        $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'start_time' => '2025-12-01T00:00:00+00:00',
            'end_time' => '2025-12-06T23:59:59+00:00',
            'endpoint_id' => 'ep_550e8400e29b41d4a716446655440000',
        ], $body);
    }

    public function testEndpointPauseStateSurface(): void
    {
        $client = $this->makeClient([
            $this->jsonResponse([
                'data' => [
                    'id' => 'ep_550e8400e29b41d4a716446655440000',
                    'url' => 'https://customer.app/webhooks',
                    'description' => 'Main production webhook',
                    'paused' => false,
                    'rate_limit_rps' => 10,
                    'burst' => 20,
                    'created_at' => '2025-12-01T10:00:00Z',
                    'updated_at' => '2025-12-06T12:00:00Z',
                ],
                'meta' => ['request_id' => 'req-12345'],
            ]),
            $this->jsonResponse([
                'data' => [[
                    'id' => 'ep_550e8400e29b41d4a716446655440000',
                    'url' => 'https://customer.app/webhooks',
                    'description' => 'Main production webhook',
                    'paused' => false,
                    'created_at' => '2025-12-01T10:00:00Z',
                ]],
                'meta' => ['request_id' => 'req-12345', 'next_cursor' => null],
            ]),
            $this->jsonResponse([
                'data' => ['id' => 'ep_550e8400e29b41d4a716446655440000', 'paused' => true],
                'meta' => ['request_id' => 'req-12345'],
            ]),
            $this->jsonResponse([
                'data' => ['id' => 'ep_550e8400e29b41d4a716446655440000', 'paused' => false, 'messages_requeued' => 6],
                'meta' => ['request_id' => 'req-12345'],
            ]),
        ]);

        $endpoint = $client->getEndpoint('ep_550e8400e29b41d4a716446655440000');
        $listed = $client->listEndpoints();
        $paused = $client->pauseEndpoint('ep_550e8400e29b41d4a716446655440000');
        $resumed = $client->resumeEndpoint('ep_550e8400e29b41d4a716446655440000');

        self::assertFalse($endpoint->paused);
        self::assertFalse($listed->endpoints[0]->paused);
        self::assertTrue($paused->paused);
        self::assertFalse($resumed->paused);
        self::assertSame(6, $resumed->messagesRequeued);
    }

    public function testPullEndpointAndEventSurface(): void
    {
        $history = [];
        $client = $this->makeClientWithHistory([
            $this->jsonResponse([
                'data' => [
                    'id' => '01935abc-def0-7123-4567-890abcdef066',
                    'name' => 'Stripe Pull',
                    'description' => 'Stores provider events for polling',
                    'mode' => 'pull',
                    'ingest_url' => 'https://ingest.hookbridge.io/pull/secret-token',
                    'secret_token' => 'secret-token',
                    'active' => true,
                    'paused' => false,
                    'retention_days' => 14,
                    'event_type_source' => 'body',
                    'event_type_path' => 'type',
                    'verify_static_token' => true,
                    'token_header_name' => 'X-Webhook-Token',
                    'verify_hmac' => false,
                    'verify_ip_allowlist' => false,
                    'ingest_response_code' => 202,
                    'idempotency_header_names' => ['X-Idempotency-Key'],
                    'created_at' => '2025-12-06T12:00:00Z',
                    'updated_at' => '2025-12-06T12:00:00Z',
                ],
                'meta' => ['request_id' => 'req-12345'],
            ], 201),
            $this->jsonResponse([
                'data' => [[
                    'id' => '01935abc-def0-7123-4567-890abcdef066',
                    'name' => 'Stripe Pull',
                    'active' => true,
                    'paused' => false,
                    'ingest_url' => 'https://ingest.hookbridge.io/pull',
                    'created_at' => '2025-12-06T12:00:00Z',
                ]],
                'meta' => ['request_id' => 'req-12345', 'next_cursor' => null],
            ]),
            $this->jsonResponse([
                'data' => [
                    'id' => '01935abc-def0-7123-4567-890abcdef066',
                    'name' => 'Stripe Pull',
                    'description' => 'Stores provider events for polling',
                    'mode' => 'pull',
                    'ingest_url' => 'https://ingest.hookbridge.io/pull',
                    'active' => true,
                    'paused' => false,
                    'retention_days' => 14,
                    'event_type_source' => 'body',
                    'event_type_path' => 'type',
                    'counts' => ['stored' => 1, 'fetched' => 0, 'delivered' => 0, 'total' => 1],
                    'verify_static_token' => true,
                    'token_header_name' => 'X-Webhook-Token',
                    'verify_hmac' => false,
                    'verify_ip_allowlist' => false,
                    'ingest_response_code' => 202,
                    'idempotency_header_names' => ['X-Idempotency-Key'],
                    'created_at' => '2025-12-06T12:00:00Z',
                    'updated_at' => '2025-12-06T12:05:00Z',
                ],
                'meta' => ['request_id' => 'req-12345'],
            ]),
            $this->jsonResponse([
                'data' => [
                    'id' => '01935abc-def0-7123-4567-890abcdef066',
                    'name' => 'Stripe Pull Renamed',
                    'description' => 'Stores provider events for polling',
                    'mode' => 'pull',
                    'ingest_url' => 'https://ingest.hookbridge.io/pull',
                    'active' => true,
                    'paused' => false,
                    'retention_days' => 21,
                    'event_type_source' => 'body',
                    'event_type_path' => 'type',
                    'counts' => ['stored' => 1, 'fetched' => 0, 'delivered' => 0, 'total' => 1],
                    'verify_static_token' => true,
                    'token_header_name' => 'X-Webhook-Token',
                    'verify_hmac' => false,
                    'verify_ip_allowlist' => false,
                    'ingest_response_code' => 202,
                    'idempotency_header_names' => ['X-Idempotency-Key'],
                    'created_at' => '2025-12-06T12:00:00Z',
                    'updated_at' => '2025-12-06T12:10:00Z',
                ],
                'meta' => ['request_id' => 'req-12345'],
            ]),
            $this->jsonResponse([
                'data' => ['id' => '01935abc-def0-7123-4567-890abcdef066', 'paused' => true],
                'meta' => ['request_id' => 'req-12345'],
            ]),
            $this->jsonResponse([
                'data' => ['id' => '01935abc-def0-7123-4567-890abcdef066', 'paused' => false],
                'meta' => ['request_id' => 'req-12345'],
            ]),
            $this->jsonResponse([
                'data' => [[
                    'id' => '01935abc-def0-7123-4567-890abcdef067',
                    'event_type' => 'payment_intent.succeeded',
                    'status' => 'stored',
                    'size_bytes' => 256,
                    'received_at' => '2025-12-06T12:01:00Z',
                    'fetched_at' => null,
                    'delivered_at' => null,
                ]],
                'meta' => ['request_id' => 'req-12345', 'has_more' => false, 'next_cursor' => ''],
            ]),
            $this->jsonResponse([
                'data' => [
                    'id' => '01935abc-def0-7123-4567-890abcdef067',
                    'event_type' => 'payment_intent.succeeded',
                    'status' => 'fetched',
                    'content_type' => 'application/json',
                    'payload' => ['type' => 'payment_intent.succeeded', 'id' => 'evt_123'],
                    'headers' => ['content-type' => 'application/json'],
                    'size_bytes' => 256,
                    'received_at' => '2025-12-06T12:01:00Z',
                    'fetched_at' => '2025-12-06T12:01:30Z',
                    'delivered_at' => null,
                ],
                'meta' => ['request_id' => 'req-12345'],
            ]),
            $this->jsonResponse([
                'data' => ['acknowledged' => 1],
                'meta' => ['request_id' => 'req-12345'],
            ]),
            $this->jsonResponse([
                'data' => ['id' => '01935abc-def0-7123-4567-890abcdef066', 'deleted' => true],
                'meta' => ['request_id' => 'req-12345'],
            ]),
        ], $history);

        $created = $client->createPullEndpoint(
            name: 'Stripe Pull',
            description: 'Stores provider events for polling',
            retentionDays: 14,
            eventTypeSource: 'body',
            eventTypePath: 'type',
            verifyStaticToken: true,
            tokenHeaderName: 'X-Webhook-Token',
            tokenValue: 'secret-token-value',
            idempotencyHeaderNames: ['X-Idempotency-Key'],
            ingestResponseCode: 202,
        );
        $listed = $client->listPullEndpoints(limit: 10);
        $fetched = $client->getPullEndpoint('01935abc-def0-7123-4567-890abcdef066');
        $updated = $client->updatePullEndpoint('01935abc-def0-7123-4567-890abcdef066', [
            'name' => 'Stripe Pull Renamed',
            'retention_days' => 21,
        ]);
        $paused = $client->pausePullEndpoint('01935abc-def0-7123-4567-890abcdef066');
        $resumed = $client->resumePullEndpoint('01935abc-def0-7123-4567-890abcdef066');
        $events = $client->listPullEvents('01935abc-def0-7123-4567-890abcdef066', 'stored', 'payment_intent.succeeded', limit: 5);
        $event = $client->getPullEvent('01935abc-def0-7123-4567-890abcdef066', '01935abc-def0-7123-4567-890abcdef067');
        $acked = $client->ackPullEvents('01935abc-def0-7123-4567-890abcdef066', ['01935abc-def0-7123-4567-890abcdef067']);
        $deleted = $client->deletePullEndpoint('01935abc-def0-7123-4567-890abcdef066');

        self::assertSame('secret-token', $created->secretToken);
        self::assertSame('01935abc-def0-7123-4567-890abcdef066', $listed->endpoints[0]->id);
        self::assertSame(1, $fetched->counts?->stored);
        self::assertSame(0, $fetched->counts?->fetched);
        self::assertSame('Stripe Pull Renamed', $updated->name);
        self::assertTrue($paused->paused);
        self::assertFalse($resumed->paused);
        self::assertSame('payment_intent.succeeded', $events->events[0]->eventType);
        self::assertNull($events->events[0]->fetchedAt);
        self::assertSame('application/json', $event->contentType);
        self::assertSame('fetched', $event->status);
        self::assertSame('2025-12-06T12:01:30+00:00', $event->fetchedAt?->format('c'));
        self::assertSame(1, $acked->acknowledged);
        self::assertTrue($deleted->deleted);

        $this->assertRequest($history, 0, 'POST', '/v1/pull-endpoints', [], [
            'name' => 'Stripe Pull',
            'description' => 'Stores provider events for polling',
            'retention_days' => 14,
            'event_type_source' => 'body',
            'event_type_path' => 'type',
            'verify_static_token' => true,
            'token_header_name' => 'X-Webhook-Token',
            'token_value' => 'secret-token-value',
            'idempotency_header_names' => ['X-Idempotency-Key'],
            'ingest_response_code' => 202,
        ]);
        $this->assertRequest($history, 3, 'PATCH', '/v1/pull-endpoints/01935abc-def0-7123-4567-890abcdef066', [], [
            'name' => 'Stripe Pull Renamed',
            'retention_days' => 21,
        ]);
        $this->assertRequest($history, 8, 'POST', '/v1/pull-endpoints/01935abc-def0-7123-4567-890abcdef066/events/ack', [], [
            'event_ids' => ['01935abc-def0-7123-4567-890abcdef067'],
        ]);
    }

    public function testPullObservabilitySurface(): void
    {
        $client = $this->makeClient([
            $this->jsonResponse([
                'data' => [[
                    'event_id' => '01935abc-def0-7123-4567-890abcdef067',
                    'pull_endpoint_id' => '01935abc-def0-7123-4567-890abcdef066',
                    'endpoint_name' => 'Stripe Pull',
                    'event_type' => 'payment_intent.succeeded',
                    'status' => 'fetched',
                    'size_bytes' => 256,
                    'received_at' => '2025-12-06T12:01:00Z',
                    'fetched_at' => '2025-12-06T12:01:30Z',
                    'delivered_at' => null,
                ]],
                'meta' => ['request_id' => 'req-12345', 'has_more' => false, 'next_cursor' => ''],
            ]),
            $this->jsonResponse([
                'data' => [
                    'window' => '24h',
                    'total_messages' => 10,
                    'succeeded' => 4,
                    'failed' => 0,
                    'retries' => 0,
                    'success_rate' => 0.4,
                    'avg_latency_ms' => 15,
                ],
                'meta' => ['request_id' => 'req-12345'],
            ]),
            $this->jsonResponse([
                'data' => [
                    'window' => '24h',
                    'buckets' => [[
                        'timestamp' => '2025-12-06T12:00:00Z',
                        'succeeded' => 4,
                        'stored' => 4,
                        'fetched' => 2,
                        'total' => 10,
                    ]],
                ],
                'meta' => ['request_id' => 'req-12345'],
            ]),
        ]);

        $logs = $client->getPullLogs('01935abc-def0-7123-4567-890abcdef066', 'delivered', 'payment_intent.succeeded', limit: 10);
        $metrics = $client->getPullMetrics(pullEndpointId: '01935abc-def0-7123-4567-890abcdef066');
        $timeseries = $client->getPullTimeseriesMetrics(pullEndpointId: '01935abc-def0-7123-4567-890abcdef066');

        self::assertSame('01935abc-def0-7123-4567-890abcdef066', $logs->entries[0]->pullEndpointId);
        self::assertSame('2025-12-06T12:01:30+00:00', $logs->entries[0]->fetchedAt?->format('c'));
        self::assertSame(10, $metrics->totalMessages);
        self::assertSame(2, $timeseries->buckets[0]->fetched);
        self::assertSame(10, $timeseries->buckets[0]->total);
    }

    public function testInboundObservabilitySurface(): void
    {
        $client = $this->makeClient([
            $this->jsonResponse([
                'data' => [[
                    'message_id' => '01935abc-def0-7123-4567-890abcdef013',
                    'inbound_endpoint_id' => '01935abc-def0-7123-4567-890abcdef099',
                    'endpoint' => 'https://myapp.com/webhooks/stripe',
                    'status' => 'succeeded',
                    'attempt_count' => 1,
                    'received_at' => '2025-12-06T12:00:00Z',
                    'delivered_at' => '2025-12-06T12:00:05Z',
                    'response_status' => 200,
                    'response_latency_ms' => 120,
                    'total_delivery_ms' => 5000,
                ]],
                'meta' => ['request_id' => 'req-12345', 'has_more' => false],
            ]),
            $this->jsonResponse([
                'data' => [
                    'window' => '24h',
                    'total_messages' => 5000,
                    'succeeded' => 4900,
                    'failed' => 20,
                    'retries' => 80,
                    'success_rate' => 0.98,
                    'avg_latency_ms' => 150,
                    'avg_delivery_time_ms' => 3200,
                ],
                'meta' => ['request_id' => 'req-12345'],
            ]),
            $this->jsonResponse([
                'data' => [
                    'window' => '24h',
                    'buckets' => [[
                        'timestamp' => '2025-12-06T00:00:00Z',
                        'succeeded' => 200,
                        'failed' => 2,
                        'retrying' => 5,
                        'total' => 207,
                        'avg_latency_ms' => 145,
                    ]],
                ],
                'meta' => ['request_id' => 'req-12345'],
            ]),
            $this->jsonResponse([
                'data' => [[
                    'id' => '01935abc-def0-7123-4567-890abcdef050',
                    'reason_code' => 'hmac_failed',
                    'received_at' => '2025-12-06T12:00:00Z',
                    'inbound_endpoint_id' => '01935abc-def0-7123-4567-890abcdef099',
                    'reason_detail' => 'HMAC signature mismatch',
                    'source_ip' => '203.0.113.42',
                ]],
                'meta' => ['request_id' => 'req-12345', 'has_more' => false],
            ]),
        ]);

        $logs = $client->getInboundLogs(status: 'succeeded', inboundEndpointId: '01935abc-def0-7123-4567-890abcdef099', limit: 50);
        $metrics = $client->getInboundMetrics(inboundEndpointId: '01935abc-def0-7123-4567-890abcdef099');
        $timeseries = $client->getInboundTimeseriesMetrics(inboundEndpointId: '01935abc-def0-7123-4567-890abcdef099');
        $rejections = $client->listInboundRejections(inboundEndpointId: '01935abc-def0-7123-4567-890abcdef099', limit: 25);

        self::assertSame(5000, $logs->entries[0]->totalDeliveryMs);
        self::assertSame(3200, $metrics->avgDeliveryTimeMs);
        self::assertSame(207, $timeseries->buckets[0]->total);
        self::assertSame('hmac_failed', $rejections->entries[0]->reasonCode);
    }

    public function testAdditionalEndpointSurface(): void
    {
        $client = $this->makeClient([
            $this->jsonResponse([
                'data' => [[
                    'id' => '01935abc-def0-7123-4567-890abcdef001',
                    'attempt_no' => 1,
                    'response_status' => 200,
                    'response_latency_ms' => 120,
                    'processing_ms' => 140,
                    'created_at' => '2025-12-06T12:00:00Z',
                ]],
                'meta' => ['has_more' => false],
            ]),
            $this->jsonResponse([
                'data' => [
                    'id' => '01935abc-def0-7123-4567-890abcdef012',
                    'project_id' => 'proj_abc123',
                    'inbound_endpoint_id' => '01935abc-def0-7123-4567-890abcdef099',
                    'status' => 'succeeded',
                    'attempt_count' => 1,
                    'replay_count' => 0,
                    'content_type' => 'application/json',
                    'size_bytes' => 512,
                    'payload_sha256' => 'abc123',
                    'response_status' => 200,
                    'response_latency_ms' => 120,
                    'received_at' => '2025-12-06T12:00:00Z',
                    'updated_at' => '2025-12-06T12:00:05Z',
                    'delivered_at' => '2025-12-06T12:00:05Z',
                ],
            ]),
            $this->jsonResponse([
                'data' => [[
                    'id' => '01935abc-def0-7123-4567-890abcdef001',
                    'attempt_no' => 1,
                    'response_status' => 200,
                    'response_latency_ms' => 120,
                    'dns_ms' => 10,
                    'created_at' => '2025-12-06T12:00:00Z',
                ]],
                'meta' => ['has_more' => false],
            ]),
            new Response(204),
        ]);

        $messageAttempts = $client->getMessageAttempts('01935abc-def0-7123-4567-890abcdef013');
        $inboundMessage = $client->getInboundMessage('01935abc-def0-7123-4567-890abcdef012');
        $inboundAttempts = $client->getInboundMessageAttempts('01935abc-def0-7123-4567-890abcdef012');
        $client->deleteExport('01935abc-def0-7123-4567-890abcdef077');

        self::assertSame(1, $messageAttempts->attempts[0]->attemptNo);
        self::assertSame(140, $messageAttempts->attempts[0]->processingMs);
        self::assertSame('proj_abc123', $inboundMessage->projectId);
        self::assertSame(10, $inboundAttempts->attempts[0]->dnsMs);
        self::assertFalse($inboundAttempts->hasMore);
    }

    public function testOutboundAdminAndExportSurface(): void
    {
        $managementHistory = [];
        $sendHistory = [];
        $client = $this->makeClientWithRequestHistory([
            $this->jsonResponse([
                'data' => [
                    'id' => 'msg_01935abc',
                    'project_id' => 'proj_abc123',
                    'endpoint_id' => 'ep_550e8400e29b41d4a716446655440000',
                    'status' => 'queued',
                    'attempt_count' => 1,
                    'replay_count' => 0,
                    'content_type' => 'application/json',
                    'size_bytes' => 512,
                    'payload_sha256' => 'abc123',
                    'created_at' => '2025-12-06T12:00:00Z',
                    'updated_at' => '2025-12-06T12:00:01Z',
                    'response_status' => 202,
                    'response_latency_ms' => 25,
                ],
            ]),
            new Response(204),
            new Response(204),
            new Response(204),
            $this->jsonResponse([
                'data' => [[
                    'message_id' => 'msg_01935abc',
                    'endpoint' => 'https://customer.app/webhooks',
                    'status' => 'succeeded',
                    'attempt_count' => 1,
                    'created_at' => '2025-12-06T12:00:00Z',
                    'delivered_at' => '2025-12-06T12:00:01Z',
                    'response_status' => 200,
                    'response_latency_ms' => 45,
                ]],
                'meta' => ['has_more' => false, 'next_cursor' => null],
            ]),
            $this->jsonResponse([
                'data' => [
                    'window' => '7d',
                    'total_messages' => 120,
                    'succeeded' => 110,
                    'failed' => 5,
                    'retries' => 5,
                    'success_rate' => 0.916,
                    'avg_latency_ms' => 145,
                ],
            ]),
            $this->jsonResponse([
                'data' => [
                    'messages' => [[
                        'message_id' => 'msg_dlq_1',
                        'endpoint' => 'https://customer.app/webhooks',
                        'status' => 'failed_permanent',
                        'attempt_count' => 5,
                        'created_at' => '2025-12-05T12:00:00Z',
                        'last_error' => 'upstream timeout',
                    ]],
                    'has_more' => true,
                    'next_cursor' => 'cursor_dlq_next',
                ],
            ]),
            new Response(204),
            $this->jsonResponse([
                'data' => [[
                    'key_id' => 'key_123',
                    'prefix' => 'hb_test',
                    'created_at' => '2025-12-01T10:00:00Z',
                    'label' => 'SDK Test Key',
                    'last_used_at' => '2025-12-06T12:00:00Z',
                ]],
            ]),
            $this->jsonResponse([
                'data' => [
                    'key_id' => 'key_456',
                    'key' => 'hb_test_new_secret',
                    'prefix' => 'hb_test',
                    'created_at' => '2025-12-06T12:00:00Z',
                    'label' => 'Created Key',
                ],
            ]),
            new Response(204),
            $this->jsonResponse([
                'data' => [
                    'id' => 'ep_550e8400e29b41d4a716446655440000',
                    'url' => 'https://customer.app/webhooks',
                    'signing_secret' => 'whsec_original_secret',
                    'description' => 'Primary outbound endpoint',
                    'created_at' => '2025-12-01T10:00:00Z',
                ],
            ]),
            $this->jsonResponse([
                'data' => [
                    'id' => 'ep_550e8400e29b41d4a716446655440000',
                    'url' => 'https://customer.app/updated-webhooks',
                    'description' => 'Updated outbound endpoint',
                    'hmac_enabled' => false,
                    'rate_limit_rps' => 15,
                    'burst' => 25,
                    'headers' => ['X-Test' => '2'],
                    'paused' => false,
                    'created_at' => '2025-12-01T10:00:00Z',
                    'updated_at' => '2025-12-06T12:30:00Z',
                ],
            ]),
            new Response(204),
            $this->jsonResponse([
                'data' => [
                    'id' => 'sk_550e8400e29b41d4a716446655440001',
                    'signing_secret' => 'whsec_rotated_secret',
                    'key_hint' => '1234',
                    'created_at' => '2025-12-06T12:31:00Z',
                ],
            ]),
            $this->jsonResponse([
                'data' => [[
                    'id' => '01935abc-def0-7123-4567-890abcdef077',
                    'project_id' => 'proj_abc123',
                    'status' => 'completed',
                    'filter_start_time' => '2025-12-01T00:00:00Z',
                    'filter_end_time' => '2025-12-06T23:59:59Z',
                    'created_at' => '2025-12-06T12:00:00Z',
                    'row_count' => 125,
                ]],
            ]),
            $this->jsonResponse([
                'data' => [
                    'id' => '01935abc-def0-7123-4567-890abcdef077',
                    'project_id' => 'proj_abc123',
                    'status' => 'completed',
                    'filter_start_time' => '2025-12-01T00:00:00Z',
                    'filter_end_time' => '2025-12-06T23:59:59Z',
                    'created_at' => '2025-12-06T12:00:00Z',
                    'file_size_bytes' => 2048,
                    'expires_at' => '2025-12-13T12:00:00Z',
                ],
            ]),
            new Response(302, ['Location' => 'https://downloads.hookbridge.io/exports/export.csv']),
        ], [
            $this->jsonResponse([
                'data' => [
                    'message_id' => 'msg_01935abc',
                    'status' => 'queued',
                ],
            ]),
        ], $managementHistory, $sendHistory);

        $sent = $client->send(
            'ep_550e8400e29b41d4a716446655440000',
            ['event' => 'order.created', 'order_id' => '12345'],
            ['X-Correlation-Id' => 'corr_123'],
            'idem_123',
        );
        $message = $client->getMessage('msg_01935abc');
        $client->replay('msg_01935abc');
        $client->cancelRetry('msg_01935abc');
        $client->retryNow('msg_01935abc');
        $logs = $client->getLogs(
            status: 'succeeded',
            startTime: '2025-12-01T00:00:00Z',
            endTime: '2025-12-06T00:00:00Z',
            limit: 25,
            cursor: 'cursor_123',
        );
        $metrics = $client->getMetrics('7d');
        $dlq = $client->getDLQMessages(100, 'cursor_dlq');
        $client->replayFromDLQ('msg_dlq_1');
        $apiKeys = $client->listAPIKeys();
        $createdKey = $client->createAPIKey('test', 'Created Key');
        $client->deleteAPIKey('key_123');
        $createdEndpoint = $client->createEndpoint(
            'https://customer.app/webhooks',
            'Primary outbound endpoint',
            true,
            10,
            20,
            ['X-Test' => '1'],
        );
        $updatedEndpoint = $client->updateEndpoint(
            'ep_550e8400e29b41d4a716446655440000',
            url: 'https://customer.app/updated-webhooks',
            description: 'Updated outbound endpoint',
            hmacEnabled: false,
            rateLimitRps: 15,
            burst: 25,
            headers: ['X-Test' => '2'],
        );
        $client->deleteEndpoint('ep_550e8400e29b41d4a716446655440000');
        $rotatedSecret = $client->rotateEndpointSecret('ep_550e8400e29b41d4a716446655440000');
        $exports = $client->listExports();
        $export = $client->getExport('01935abc-def0-7123-4567-890abcdef077');
        $downloadUrl = $client->downloadExport('01935abc-def0-7123-4567-890abcdef077');

        self::assertSame('msg_01935abc', $sent->messageId);
        self::assertSame(202, $message->responseStatus);
        self::assertSame(200, $logs->messages[0]->responseStatus);
        self::assertSame(145, $metrics->avgLatencyMs);
        self::assertTrue($dlq->hasMore);
        self::assertSame('SDK Test Key', $apiKeys[0]->label);
        self::assertSame('hb_test_new_secret', $createdKey->key);
        self::assertSame('whsec_original_secret', $createdEndpoint->signingSecret);
        self::assertSame('https://customer.app/updated-webhooks', $updatedEndpoint->url);
        self::assertSame('whsec_rotated_secret', $rotatedSecret->signingSecret);
        self::assertSame(125, $exports[0]->rowCount);
        self::assertSame(2048, $export->fileSizeBytes);
        self::assertSame('https://downloads.hookbridge.io/exports/export.csv', $downloadUrl);

        self::assertCount(1, $sendHistory);
        $this->assertRequest($sendHistory, 0, 'POST', '/v1/webhooks/send', [], [
            'endpoint_id' => 'ep_550e8400e29b41d4a716446655440000',
            'payload' => ['event' => 'order.created', 'order_id' => '12345'],
            'headers' => ['X-Correlation-Id' => 'corr_123'],
            'idempotency_key' => 'idem_123',
        ]);

        self::assertCount(18, $managementHistory);
        $this->assertRequest($managementHistory, 0, 'GET', '/v1/messages/msg_01935abc');
        $this->assertRequest($managementHistory, 1, 'POST', '/v1/messages/msg_01935abc/replay');
        $this->assertRequest($managementHistory, 2, 'POST', '/v1/messages/msg_01935abc/cancel');
        $this->assertRequest($managementHistory, 3, 'POST', '/v1/messages/msg_01935abc/retry-now');
        $this->assertRequest($managementHistory, 4, 'GET', '/v1/logs', [
            'status' => 'succeeded',
            'start_time' => '2025-12-01T00:00:00Z',
            'end_time' => '2025-12-06T00:00:00Z',
            'limit' => '25',
            'cursor' => 'cursor_123',
        ]);
        $this->assertRequest($managementHistory, 5, 'GET', '/v1/metrics', ['window' => '7d']);
        $this->assertRequest($managementHistory, 6, 'GET', '/v1/dlq/messages', ['limit' => '100', 'cursor' => 'cursor_dlq']);
        $this->assertRequest($managementHistory, 7, 'POST', '/v1/dlq/replay/msg_dlq_1');
        $this->assertRequest($managementHistory, 8, 'GET', '/v1/api-keys');
        $this->assertRequest($managementHistory, 9, 'POST', '/v1/api-keys', [], ['mode' => 'test', 'label' => 'Created Key']);
        $this->assertRequest($managementHistory, 10, 'DELETE', '/v1/api-keys/key_123');
        $this->assertRequest($managementHistory, 11, 'POST', '/v1/endpoints', [], [
            'url' => 'https://customer.app/webhooks',
            'hmac_enabled' => true,
            'description' => 'Primary outbound endpoint',
            'rate_limit_rps' => 10,
            'burst' => 20,
            'headers' => ['X-Test' => '1'],
        ]);
        $this->assertRequest($managementHistory, 12, 'PATCH', '/v1/endpoints/ep_550e8400e29b41d4a716446655440000', [], [
            'url' => 'https://customer.app/updated-webhooks',
            'description' => 'Updated outbound endpoint',
            'hmac_enabled' => false,
            'rate_limit_rps' => 15,
            'burst' => 25,
            'headers' => ['X-Test' => '2'],
        ]);
        $this->assertRequest($managementHistory, 13, 'DELETE', '/v1/endpoints/ep_550e8400e29b41d4a716446655440000');
        $this->assertRequest($managementHistory, 14, 'POST', '/v1/endpoints/ep_550e8400e29b41d4a716446655440000/signing-keys');
        $this->assertRequest($managementHistory, 15, 'GET', '/v1/exports');
        $this->assertRequest($managementHistory, 16, 'GET', '/v1/exports/01935abc-def0-7123-4567-890abcdef077');
        $this->assertRequest($managementHistory, 17, 'GET', '/v1/exports/01935abc-def0-7123-4567-890abcdef077/download');
    }

    public function testInboundManagementAndReplaySurface(): void
    {
        $managementHistory = [];
        $sendHistory = [];
        $client = $this->makeClientWithRequestHistory([
            $this->jsonResponse([
                'data' => [
                    'id' => '01935abc-def0-7123-4567-890abcdef099',
                    'name' => 'Stripe webhooks',
                    'url' => 'https://myapp.com/webhooks/stripe',
                    'ingest_url' => 'https://receive.hookbridge.io/v1/webhooks/receive/01935abc-def0-7123-4567-890abcdef099/token_123',
                    'secret_token' => 'token_123',
                    'created_at' => '2025-12-06T12:00:00Z',
                ],
            ]),
            $this->jsonResponse([
                'data' => [[
                    'id' => '01935abc-def0-7123-4567-890abcdef099',
                    'name' => 'Stripe webhooks',
                    'url' => 'https://myapp.com/webhooks/stripe',
                    'active' => true,
                    'paused' => false,
                    'created_at' => '2025-12-06T12:00:00Z',
                ]],
                'meta' => ['next_cursor' => null],
            ]),
            $this->jsonResponse([
                'data' => [
                    'id' => '01935abc-def0-7123-4567-890abcdef099',
                    'name' => 'Stripe webhooks',
                    'description' => 'Receives Stripe events',
                    'url' => 'https://myapp.com/webhooks/stripe',
                    'active' => true,
                    'paused' => false,
                    'verify_static_token' => true,
                    'verify_hmac' => true,
                    'verify_ip_allowlist' => false,
                    'ingest_response_code' => 202,
                    'idempotency_header_names' => ['stripe-signature'],
                    'signing_enabled' => true,
                    'created_at' => '2025-12-06T12:00:00Z',
                    'updated_at' => '2025-12-06T12:05:00Z',
                ],
            ]),
            $this->jsonResponse([
                'data' => ['id' => '01935abc-def0-7123-4567-890abcdef099', 'updated' => true],
            ]),
            $this->jsonResponse([
                'data' => ['id' => '01935abc-def0-7123-4567-890abcdef099', 'paused' => true],
            ]),
            $this->jsonResponse([
                'data' => ['id' => '01935abc-def0-7123-4567-890abcdef099', 'paused' => false],
            ]),
            $this->jsonResponse([
                'data' => ['message_id' => 'inm_01935abc', 'status' => 'queued'],
            ]),
            $this->jsonResponse([
                'data' => [
                    'replayed' => 2,
                    'failed' => 0,
                    'stuck' => 0,
                    'replayed_message_ids' => ['inm_01935abc', 'inm_01935abd'],
                    'stuck_message_ids' => [],
                ],
            ]),
            $this->jsonResponse([
                'data' => [
                    'replayed' => 1,
                    'failed' => 1,
                    'stuck' => 0,
                    'results' => [
                        ['message_id' => 'inm_01935abc', 'status' => 'replayed'],
                        ['message_id' => 'inm_01935abd', 'status' => 'failed', 'error' => 'inbound message missing'],
                    ],
                ],
            ]),
            $this->jsonResponse([
                'data' => ['id' => '01935abc-def0-7123-4567-890abcdef099', 'deleted' => true],
            ]),
        ], [], $managementHistory, $sendHistory);

        $created = $client->createInboundEndpoint(
            'https://myapp.com/webhooks/stripe',
            name: 'Stripe webhooks',
            description: 'Receives Stripe events',
            verifyStaticToken: true,
            tokenHeaderName: 'X-Webhook-Token',
            tokenValue: 'token_123',
            verifyHmac: true,
            hmacHeaderName: 'Stripe-Signature',
            hmacSecret: 'whsec_inbound_secret',
            timestampHeaderName: 'Stripe-Timestamp',
            timestampTtlSeconds: 300,
            verifyIpAllowlist: false,
            idempotencyHeaderNames: ['stripe-signature'],
            ingestResponseCode: 202,
            signingEnabled: true,
        );
        $listed = $client->listInboundEndpoints(25, 'cursor_inbound');
        $inbound = $client->getInboundEndpoint('01935abc-def0-7123-4567-890abcdef099');
        $updated = $client->updateInboundEndpoint('01935abc-def0-7123-4567-890abcdef099', [
            'description' => 'Updated Stripe events endpoint',
            'active' => true,
        ]);
        $paused = $client->pauseInboundEndpoint('01935abc-def0-7123-4567-890abcdef099');
        $resumed = $client->resumeInboundEndpoint('01935abc-def0-7123-4567-890abcdef099');
        $replayed = $client->replayInboundMessage('inm_01935abc');
        $replayAll = $client->replayAllInboundMessages('failed_permanent', '01935abc-def0-7123-4567-890abcdef099', 10);
        $replayBatch = $client->replayBatchInboundMessages(['inm_01935abc', 'inm_01935abd']);
        $deleted = $client->deleteInboundEndpoint('01935abc-def0-7123-4567-890abcdef099');

        self::assertSame('token_123', $created->secretToken);
        self::assertFalse($listed->endpoints[0]->paused);
        self::assertTrue($inbound->verifyStaticToken);
        self::assertTrue($updated->updated);
        self::assertTrue($paused->paused);
        self::assertFalse($resumed->paused);
        self::assertSame('queued', $replayed->status);
        self::assertSame(2, $replayAll->replayed);
        self::assertSame('inbound message missing', $replayBatch->results[1]->error);
        self::assertTrue($deleted->deleted);
        self::assertCount(0, $sendHistory);

        self::assertCount(10, $managementHistory);
        $this->assertRequest($managementHistory, 0, 'POST', '/v1/inbound-endpoints', [], [
            'url' => 'https://myapp.com/webhooks/stripe',
            'name' => 'Stripe webhooks',
            'description' => 'Receives Stripe events',
            'verify_static_token' => true,
            'token_header_name' => 'X-Webhook-Token',
            'token_value' => 'token_123',
            'verify_hmac' => true,
            'hmac_header_name' => 'Stripe-Signature',
            'hmac_secret' => 'whsec_inbound_secret',
            'timestamp_header_name' => 'Stripe-Timestamp',
            'timestamp_ttl_seconds' => 300,
            'verify_ip_allowlist' => false,
            'idempotency_header_names' => ['stripe-signature'],
            'ingest_response_code' => 202,
            'signing_enabled' => true,
        ]);
        $this->assertRequest($managementHistory, 1, 'GET', '/v1/inbound-endpoints', ['limit' => '25', 'cursor' => 'cursor_inbound']);
        $this->assertRequest($managementHistory, 2, 'GET', '/v1/inbound-endpoints/01935abc-def0-7123-4567-890abcdef099');
        $this->assertRequest($managementHistory, 3, 'PATCH', '/v1/inbound-endpoints/01935abc-def0-7123-4567-890abcdef099', [], [
            'description' => 'Updated Stripe events endpoint',
            'active' => true,
        ]);
        $this->assertRequest($managementHistory, 4, 'POST', '/v1/inbound-endpoints/01935abc-def0-7123-4567-890abcdef099/pause');
        $this->assertRequest($managementHistory, 5, 'POST', '/v1/inbound-endpoints/01935abc-def0-7123-4567-890abcdef099/resume');
        $this->assertRequest($managementHistory, 6, 'POST', '/v1/inbound-messages/inm_01935abc/replay');
        $this->assertRequest($managementHistory, 7, 'POST', '/v1/inbound-messages/replay-all', [
            'status' => 'failed_permanent',
            'inbound_endpoint_id' => '01935abc-def0-7123-4567-890abcdef099',
            'limit' => '10',
        ]);
        $this->assertRequest($managementHistory, 8, 'POST', '/v1/inbound-messages/replay-batch', [], [
            'message_ids' => ['inm_01935abc', 'inm_01935abd'],
        ]);
        $this->assertRequest($managementHistory, 9, 'DELETE', '/v1/inbound-endpoints/01935abc-def0-7123-4567-890abcdef099');
    }

    public function testDeleteMessagesAndActors(): void
    {
        $managementHistory = [];
        $responses = [
            new Response(200, [], json_encode([
                'data' => [
                    'message_id' => 'm_1',
                    'deleted_at' => '2026-04-05T14:23:11.123Z',
                    'already_deleted' => false,
                ],
                'meta' => ['request_id' => 'req-del-1'],
            ])),
            new Response(200, [], json_encode([
                'data' => [
                    'results' => [
                        ['message_id' => 'm_1', 'outcome' => 'deleted', 'deleted_at' => '2026-04-05T14:23:11.123Z'],
                        ['message_id' => 'm_missing', 'outcome' => 'not_found'],
                    ],
                    'deleted_count' => 1,
                    'already_deleted_count' => 0,
                    'not_found_count' => 1,
                ],
                'meta' => ['request_id' => 'req-del-2'],
            ])),
            new Response(200, [], json_encode([
                'data' => ['deleted' => 2, 'deleted_message_ids' => ['m_1', 'm_2']],
                'meta' => ['request_id' => 'req-del-3'],
            ])),
            new Response(200, [], json_encode([
                'data' => [
                    'event_id' => 'ev_1',
                    'deleted_at' => '2026-04-05T14:23:11.123Z',
                    'already_deleted' => false,
                ],
                'meta' => ['request_id' => 'req-del-4'],
            ])),
            new Response(200, [], json_encode([
                'data' => [
                    'results' => [
                        ['event_id' => 'ev_1', 'outcome' => 'deleted', 'deleted_at' => '2026-04-05T14:23:11.123Z'],
                    ],
                    'deleted_count' => 1,
                    'already_deleted_count' => 0,
                    'not_found_count' => 0,
                ],
                'meta' => ['request_id' => 'req-del-5'],
            ])),
            new Response(200, [], json_encode([
                'data' => ['deleted' => 3, 'deleted_event_ids' => ['ev_1', 'ev_2', 'ev_3']],
                'meta' => ['request_id' => 'req-del-6'],
            ])),
            new Response(200, [], json_encode([
                'data' => [
                    'users' => ['user_1' => ['email' => 'alice@example.com']],
                    'api_keys' => ['key_1' => ['label' => 'Production']],
                ],
                'meta' => ['request_id' => 'req-actors'],
            ])),
        ];
        $client = $this->makeClientWithHistory($responses, $managementHistory);

        $single = $client->deleteMessage('m_1');
        $this->assertSame('m_1', $single->messageId);
        $this->assertFalse($single->alreadyDeleted);

        $batch = $client->deleteMessagesBatch(['m_1', 'm_missing']);
        $this->assertSame(1, $batch->deletedCount);
        $this->assertSame(1, $batch->notFoundCount);
        $this->assertSame('deleted', $batch->results[0]->outcome);

        $all = $client->deleteMessagesAll(status: 'succeeded', limit: 500);
        $this->assertSame(2, $all->deleted);
        $this->assertSame(['m_1', 'm_2'], $all->deletedMessageIds);

        $pullSingle = $client->deletePullEvent('pe_1', 'ev_1');
        $this->assertSame('ev_1', $pullSingle->eventId);

        $pullBatch = $client->deletePullEventsBatch('pe_1', ['ev_1']);
        $this->assertSame(1, $pullBatch->deletedCount);

        $pullAll = $client->deletePullEventsAll('pe_1', eventType: 'user.created');
        $this->assertSame(3, $pullAll->deleted);

        $actors = $client->lookupActors(userIds: ['user_1'], apiKeyIds: ['key_1']);
        $this->assertSame('alice@example.com', $actors->users['user_1']['email']);
        $this->assertSame('Production', $actors->apiKeys['key_1']['label']);

        $this->assertRequest($managementHistory, 0, 'DELETE', '/v1/messages/m_1');
        $this->assertRequest($managementHistory, 1, 'POST', '/v1/messages/delete-batch', [], ['message_ids' => ['m_1', 'm_missing']]);
        $this->assertRequest($managementHistory, 2, 'POST', '/v1/messages/delete-all', ['status' => 'succeeded', 'limit' => '500']);
        $this->assertRequest($managementHistory, 3, 'DELETE', '/v1/pull-endpoints/pe_1/events/ev_1');
        $this->assertRequest($managementHistory, 4, 'POST', '/v1/pull-endpoints/pe_1/events/delete-batch', [], ['message_ids' => ['ev_1']]);
        $this->assertRequest($managementHistory, 5, 'POST', '/v1/pull-endpoints/pe_1/events/delete-all', ['event_type' => 'user.created']);
        $this->assertRequest($managementHistory, 6, 'GET', '/v1/actors/lookup', ['user_id' => 'user_1', 'api_key_id' => 'key_1']);
    }
}
