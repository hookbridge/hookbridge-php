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

    private function jsonResponse(array $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, array_merge(['Content-Type' => 'application/json'], $headers), json_encode($data, JSON_THROW_ON_ERROR));
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
}
