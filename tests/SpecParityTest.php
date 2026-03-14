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
            $this->jsonResponse(['data' => [['id' => '01935abc-def0-7123-4567-890abcdef012', 'tenant_id' => 'tenant_abc123', 'name' => 'Production Webhooks', 'status' => 'active', 'rate_limit_default' => 1000, 'created_at' => '2025-12-01T10:00:00Z']]]),
            $this->jsonResponse(['data' => ['id' => '01935abc-def0-7123-4567-890abcdef012', 'tenant_id' => 'tenant_abc123', 'name' => 'Production Webhooks', 'status' => 'active', 'rate_limit_default' => 1000, 'created_at' => '2025-12-01T10:00:00Z']]),
            $this->jsonResponse(['data' => ['id' => '01935abc-def0-7123-4567-890abcdef012', 'tenant_id' => 'tenant_abc123', 'name' => 'Renamed Project', 'status' => 'active', 'rate_limit_default' => 2000, 'created_at' => '2025-12-01T10:00:00Z']]),
            $this->jsonResponse(['data' => ['deleted' => true]]),
            $this->jsonResponse(['data' => ['id' => 'sk_550e8400e29b41d4a716446655440001', 'signing_secret' => 'whsec_new_secret_12345', 'key_hint' => '1234', 'created_at' => '2025-01-01T00:00:00Z']]),
            $this->jsonResponse(['data' => [['id' => 'sk_550e8400e29b41d4a716446655440001', 'key_hint' => '1234', 'created_at' => '2025-01-01T00:00:00Z']]]),
            new Response(204),
            $this->jsonResponse(['data' => ['window' => '24h', 'buckets' => [['timestamp' => '2025-12-06T00:00:00Z', 'succeeded' => 10, 'failed' => 1, 'retrying' => 2, 'total' => 13, 'avg_latency_ms' => 180]]]]),
            $this->jsonResponse(['data' => ['session_id' => 'cs_test_abc123', 'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_abc123']]),
            $this->jsonResponse(['data' => ['portal_url' => 'https://billing.stripe.com/p/session/abc123']]),
            $this->jsonResponse(['data' => ['plan' => 'starter', 'status' => 'active', 'limits' => ['plan' => 'starter', 'messages_per_month' => 5000, 'max_projects' => 3, 'max_endpoints' => 25, 'retention_days' => 30], 'usage' => ['messages_used' => 123, 'period_start' => '2026-02-01T00:00:00Z', 'period_end' => '2026-02-28T23:59:59Z'], 'cancel_at_period_end' => false, 'current_period_end' => '2026-03-01T00:00:00Z']]),
            $this->jsonResponse(['data' => [['period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'message_count' => 6102, 'overage_count' => 1102, 'plan_limit' => 5000]], 'meta' => ['total' => 6, 'limit' => 12, 'offset' => 0, 'has_more' => false]]),
            $this->jsonResponse(['data' => [['id' => 'in_1abc', 'status' => 'paid', 'amount_due' => 1000, 'amount_paid' => 1000, 'currency' => 'usd', 'period_start' => '2026-02-05T00:00:00Z', 'period_end' => '2026-03-05T00:00:00Z', 'created' => '2026-03-05T06:00:00Z', 'invoice_pdf' => 'https://pay.stripe.com/invoice/abc', 'hosted_invoice_url' => 'https://invoice.stripe.com/abc', 'lines' => [['description' => 'Starter Plan (Monthly)', 'amount' => 1000, 'quantity' => 1]]]], 'meta' => ['has_more' => false]]),
        ]);

        $replayAll = $client->replayAllMessages('failed_permanent', 'ep_550e8400e29b41d4a716446655440000', 50);
        $replayBatch = $client->replayBatchMessages(['01935abc-def0-7123-4567-890abcdef013', '01935abc-def0-7123-4567-890abcdef014']);
        $projects = $client->listProjects();
        $createdProject = $client->createProject('Production Webhooks', 1000);
        $updatedProject = $client->updateProject('01935abc-def0-7123-4567-890abcdef012', 'Renamed Project', 2000);
        $client->deleteProject('01935abc-def0-7123-4567-890abcdef012');
        $createdSigningKey = $client->createEndpointSigningKey('ep_550e8400e29b41d4a716446655440000');
        $signingKeys = $client->listEndpointSigningKeys('ep_550e8400e29b41d4a716446655440000');
        $client->deleteEndpointSigningKey('ep_550e8400e29b41d4a716446655440000', 'sk_550e8400e29b41d4a716446655440001');
        $timeseries = $client->getTimeseriesMetrics(endpointId: 'ep_550e8400e29b41d4a716446655440000');
        $checkout = $client->createCheckout('pro', 'monthly');
        $portal = $client->createPortal('https://app.hookbridge.io/billing');
        $subscription = $client->getSubscription();
        $usage = $client->getUsageHistory();
        $invoices = $client->getInvoices();

        self::assertSame(2, $replayAll->replayed);
        self::assertSame('message not replayable', $replayBatch->results[1]->error);
        self::assertSame('Production Webhooks', $projects[0]->name);
        self::assertSame('01935abc-def0-7123-4567-890abcdef012', $createdProject->id);
        self::assertSame('Renamed Project', $updatedProject->name);
        self::assertSame('whsec_new_secret_12345', $createdSigningKey->signingSecret);
        self::assertSame('sk_550e8400e29b41d4a716446655440001', $signingKeys[0]->id);
        self::assertSame(1, $timeseries->buckets[0]->failed);
        self::assertSame('cs_test_abc123', $checkout->sessionId);
        self::assertSame('https://billing.stripe.com/p/session/abc123', $portal->portalUrl);
        self::assertSame('starter', $subscription->plan);
        self::assertSame(123, $subscription->usage->messagesUsed);
        self::assertSame(6102, $usage->rows[0]->messageCount);
        self::assertSame(1, $invoices->invoices[0]->lines[0]->quantity);
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
                'data' => [
                    'id' => '01935abc-def0-7123-4567-890abcdef012',
                    'tenant_id' => 'tenant_abc123',
                    'name' => 'Production Webhooks',
                    'status' => 'active',
                    'rate_limit_default' => 1000,
                    'created_at' => '2025-12-01T10:00:00Z',
                ],
            ]),
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

        $project = $client->getProject('01935abc-def0-7123-4567-890abcdef012');
        $messageAttempts = $client->getMessageAttempts('01935abc-def0-7123-4567-890abcdef013');
        $inboundMessage = $client->getInboundMessage('01935abc-def0-7123-4567-890abcdef012');
        $inboundAttempts = $client->getInboundMessageAttempts('01935abc-def0-7123-4567-890abcdef012');
        $client->deleteExport('01935abc-def0-7123-4567-890abcdef077');

        self::assertSame('Production Webhooks', $project->name);
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
}
