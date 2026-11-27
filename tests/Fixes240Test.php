<?php

declare(strict_types=1);

namespace HookBridge\Tests;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use HookBridge\Exception\ValidationException;
use HookBridge\HookBridge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class Fixes240Test extends TestCase
{
    private function makeClient(array $responses, array &$history, array $defaultHeaders = [], array $sendResponses = [], array &$sendHistory = []): HookBridge
    {
        $client = new HookBridge('hb_test_mock_key', retries: 0);

        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));
        $management = new Client(['base_uri' => 'https://api.hookbridge.io', 'handler' => $handler, 'headers' => $defaultHeaders]);

        $sendHandler = HandlerStack::create(new MockHandler($sendResponses));
        $sendHandler->push(Middleware::history($sendHistory));
        $send = new Client(['base_uri' => 'https://send.hookbridge.io', 'handler' => $sendHandler, 'headers' => $defaultHeaders]);

        $reflection = new ReflectionClass($client);
        $reflection->getProperty('client')->setValue($client, $management);
        $reflection->getProperty('sendClient')->setValue($client, $send);

        return $client;
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function empty(): Response
    {
        return new Response(200);
    }

    private function body(array $history, int $i = 0): string
    {
        return (string) $history[$i]['request']->getBody();
    }

    public function testCreateInboundEndpointWithoutNameInResponse(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => [
            'id' => 'ib_1', 'ingest_url' => 'https://i', 'secret_token' => 's', 'created_at' => '2026-01-02T03:04:05Z',
        ]])], $history);

        $result = $client->createInboundEndpoint(url: 'https://example.com/hook');

        self::assertSame('', $result->name);
    }

    public function testListInboundEndpointsLastPageHasNoMore(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => [], 'meta' => ['next_cursor' => '']])], $history);

        $result = $client->listInboundEndpoints();

        self::assertFalse($result->hasMore);
        self::assertNull($result->nextCursor);
    }

    public function testListInboundEndpointsMissingCursorHasNoMore(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => []])], $history);

        $result = $client->listInboundEndpoints();

        self::assertFalse($result->hasMore);
        self::assertNull($result->nextCursor);
    }

    public function testListInboundEndpointsWithCursorHasMore(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => [], 'meta' => ['next_cursor' => 'c2']])], $history);

        $result = $client->listInboundEndpoints();

        self::assertTrue($result->hasMore);
        self::assertSame('c2', $result->nextCursor);
    }

    private function metricsData(): array
    {
        return ['window' => '24h', 'total_messages' => 1, 'succeeded' => 1, 'failed' => 0, 'retries' => 0, 'success_rate' => 1.0, 'avg_latency_ms' => 5];
    }

    public function testGetMetricsSendsEndpointId(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => $this->metricsData()])], $history);

        $client->getMetrics('7d', 'ep_1');

        self::assertSame('window=7d&endpoint_id=ep_1', $history[0]['request']->getUri()->getQuery());
    }

    public function testGetMetricsOmitsEndpointIdByDefault(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => $this->metricsData()])], $history);

        $client->getMetrics();

        self::assertSame('window=24h', $history[0]['request']->getUri()->getQuery());
    }

    public static function clientNameOps(): array
    {
        $ok = ['data' => ['message_id' => 'm1', 'status' => 'pending']];
        $bulk = ['data' => ['replayed' => 0, 'failed' => 0, 'stuck' => 0]];
        return [
            'replay' => [static fn(HookBridge $c, ?string $n) => $c->replay('m1', $n), $ok],
            'cancelRetry' => [static fn(HookBridge $c, ?string $n) => $c->cancelRetry('m1', $n), $ok],
            'retryNow' => [static fn(HookBridge $c, ?string $n) => $c->retryNow('m1', $n), $ok],
            'replayAllMessages' => [static fn(HookBridge $c, ?string $n) => $c->replayAllMessages('failed_permanent', null, null, $n), $bulk],
            'replayBatchMessages' => [static fn(HookBridge $c, ?string $n) => $c->replayBatchMessages(['m1'], $n), $bulk],
            'replayFromDLQ' => [static fn(HookBridge $c, ?string $n) => $c->replayFromDLQ('m1', $n), $ok],
            'replayInboundMessage' => [static fn(HookBridge $c, ?string $n) => $c->replayInboundMessage('m1', $n), $ok],
            'replayAllInboundMessages' => [static fn(HookBridge $c, ?string $n) => $c->replayAllInboundMessages('failed_permanent', null, null, $n), $bulk],
            'replayBatchInboundMessages' => [static fn(HookBridge $c, ?string $n) => $c->replayBatchInboundMessages(['m1'], $n), $bulk],
        ];
    }

    #[DataProvider('clientNameOps')]
    public function testPerCallClientNameOverridesClientLevel(callable $op, array $response): void
    {
        $history = [];
        $client = $this->makeClient([$this->json($response)], $history, ['X-Hookbridge-Client' => 'global']);

        $op($client, 'per-call');

        self::assertSame(['per-call'], $history[0]['request']->getHeader('X-Hookbridge-Client'));
    }

    #[DataProvider('clientNameOps')]
    public function testClientLevelNameUsedWhenNoPerCallName(callable $op, array $response): void
    {
        $history = [];
        $client = $this->makeClient([$this->json($response)], $history, ['X-Hookbridge-Client' => 'global']);

        $op($client, null);

        self::assertSame(['global'], $history[0]['request']->getHeader('X-Hookbridge-Client'));
    }

    #[DataProvider('clientNameOps')]
    public function testInvalidPerCallClientNameRejectedBeforeRequest(callable $op, array $response): void
    {
        $history = [];
        $client = $this->makeClient([$this->json($response)], $history);

        try {
            $op($client, "bad\r\nname");
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertCount(0, $history);
        }
    }

    public function testPerCallClientNameDoesNotLeakToNextRequest(): void
    {
        $history = [];
        $client = $this->makeClient([
            $this->json(['data' => ['message_id' => 'm1', 'status' => 'pending']]),
            $this->json(['data' => ['message_id' => 'm1', 'status' => 'pending']]),
        ], $history, ['X-Hookbridge-Client' => 'global']);

        $client->replay('m1', 'once');
        $client->replay('m1');

        self::assertSame(['global'], $history[1]['request']->getHeader('X-Hookbridge-Client'));
    }

    public function testReplayReturnsSendResponse(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['message' => 'queued', 'data' => ['message_id' => 'm1', 'status' => 'pending']])], $history);

        $result = $client->replay('m1');

        self::assertSame('m1', $result->messageId);
        self::assertSame('pending', $result->status);
        self::assertSame('queued', $result->message);
    }

    public function testCancelRetryReturnsSendResponse(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => ['message_id' => 'm1', 'status' => 'failed_permanent']])], $history);

        $result = $client->cancelRetry('m1');

        self::assertSame('failed_permanent', $result->status);
        self::assertNull($result->message);
    }

    public function testRetryNowReturnsSendResponse(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => ['message_id' => 'm1', 'status' => 'pending']])], $history);

        self::assertSame('pending', $client->retryNow('m1')->status);
    }

    public function testReplayFromDLQReturnsSendResponse(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => ['message_id' => 'm1', 'status' => 'pending']])], $history);

        self::assertSame('m1', $client->replayFromDLQ('m1')->messageId);
    }

    public function testReplayOpsTolerateEmptyBody(): void
    {
        $history = [];
        $client = $this->makeClient([$this->empty(), $this->empty(), $this->empty(), $this->empty()], $history);

        foreach ([$client->replay('m1'), $client->cancelRetry('m1'), $client->retryNow('m1'), $client->replayFromDLQ('m1')] as $result) {
            self::assertSame('m1', $result->messageId);
            self::assertSame('', $result->status);
            self::assertNull($result->message);
        }
    }

    public function testDeleteEndpointReturnsDeleteResult(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => ['id' => 'ep_1', 'deleted' => true]])], $history);

        $result = $client->deleteEndpoint('ep_1');

        self::assertTrue($result->deleted);
        self::assertSame('ep_1', $result->id);
    }

    public function testDeleteAPIKeyReturnsDeleteResult(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => ['deleted' => true]])], $history);

        $result = $client->deleteAPIKey('key_1');

        self::assertTrue($result->deleted);
        self::assertNull($result->id);
    }

    public function testDeletesTolerateEmptyBody(): void
    {
        $history = [];
        $client = $this->makeClient([$this->empty(), $this->empty()], $history);

        $endpoint = $client->deleteEndpoint('ep_1');
        $key = $client->deleteAPIKey('key_1');

        self::assertTrue($endpoint->deleted);
        self::assertSame('ep_1', $endpoint->id);
        self::assertTrue($key->deleted);
    }

    public function testReplayAllMessagesMapsMessage(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['message' => 'done', 'data' => ['replayed' => 1, 'failed' => 0, 'stuck' => 0]])], $history);

        self::assertSame('done', $client->replayAllMessages('failed_permanent')->message);
    }

    public function testReplayBatchMessagesMapsMessage(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['message' => 'done', 'data' => ['replayed' => 1, 'failed' => 0, 'stuck' => 0]])], $history);

        self::assertSame('done', $client->replayBatchMessages(['m1'])->message);
    }

    public function testInboundReplayResponsesMapMessage(): void
    {
        $history = [];
        $bulk = ['message' => 'done', 'data' => ['replayed' => 1, 'failed' => 0, 'stuck' => 0]];
        $client = $this->makeClient([
            $this->json(['message' => 'hi', 'data' => ['message_id' => 'm1', 'status' => 'pending']]),
            $this->json($bulk),
            $this->json($bulk),
        ], $history);

        self::assertSame('hi', $client->replayInboundMessage('m1')->message);
        self::assertSame('done', $client->replayAllInboundMessages('failed_permanent')->message);
        self::assertSame('done', $client->replayBatchInboundMessages(['m1'])->message);
    }

    public function testPullLogsMapDeletedAt(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => [[
            'event_id' => 'e1', 'pull_endpoint_id' => 'p1', 'status' => 'stored', 'size_bytes' => 1,
            'received_at' => '2026-01-02T03:04:05Z', 'deleted_at' => '2026-01-03T00:00:00Z',
        ], [
            'event_id' => 'e2', 'pull_endpoint_id' => 'p1', 'status' => 'stored', 'size_bytes' => 1,
            'received_at' => '2026-01-02T03:04:05Z',
        ]], 'meta' => ['has_more' => false]])], $history);

        $entries = $client->getPullLogs()->entries;

        self::assertInstanceOf(DateTimeImmutable::class, $entries[0]->deletedAt);
        self::assertSame('2026-01-03', $entries[0]->deletedAt->format('Y-m-d'));
        self::assertNull($entries[1]->deletedAt);
    }

    public function testInboundLogsMapDeletedAt(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => [[
            'message_id' => 'm1', 'inbound_endpoint_id' => 'ib1', 'endpoint' => 'x', 'status' => 'delivered', 'attempt_count' => 1,
            'received_at' => '2026-01-02T03:04:05Z', 'deleted_at' => '2026-01-03T00:00:00Z',
        ], [
            'message_id' => 'm2', 'inbound_endpoint_id' => 'ib1', 'endpoint' => 'x', 'status' => 'delivered', 'attempt_count' => 1,
            'received_at' => '2026-01-02T03:04:05Z',
        ]], 'meta' => ['has_more' => false]])], $history);

        $entries = $client->getInboundLogs()->entries;

        self::assertSame('2026-01-03', $entries[0]->deletedAt->format('Y-m-d'));
        self::assertNull($entries[1]->deletedAt);
    }

    public function testCreateEndpointMapsSigningKeyFields(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => [
            'id' => 'ep_1', 'url' => 'https://e', 'signing_secret' => 'sec', 'created_at' => '2026-01-02T03:04:05Z',
            'signing_key_id' => 'sk_1', 'key_hint' => 'abcd',
        ]])], $history);

        $result = $client->createEndpoint('https://e');

        self::assertSame('sk_1', $result->signingKeyId);
        self::assertSame('abcd', $result->keyHint);
    }

    public function testSigningKeyCreationMapsKeyHintAndCreatedAt(): void
    {
        $history = [];
        $key = ['data' => ['id' => 'sk_1', 'signing_secret' => 'sec', 'key_hint' => 'wxyz', 'created_at' => '2026-01-02T03:04:05Z']];
        $client = $this->makeClient([$this->json($key), $this->json($key), $this->json($key)], $history);

        foreach ([$client->createEndpointSigningKey('ep_1'), $client->rotateEndpointSecret('ep_1'), $client->createInboundSigningKey('ib_1')] as $result) {
            self::assertSame('wxyz', $result->keyHint);
            self::assertSame('2026-01-02', $result->createdAt->format('Y-m-d'));
        }
    }

    public function testSigningKeyCreationWithoutOptionalFields(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => ['id' => 'sk_1', 'signing_secret' => 'sec']])], $history);

        $result = $client->createEndpointSigningKey('ep_1');

        self::assertNull($result->keyHint);
        self::assertNull($result->createdAt);
    }

    private function inboundEndpointData(): array
    {
        return [
            'id' => 'ib_1', 'name' => 'n', 'active' => true, 'paused' => false, 'verify_static_token' => true,
            'verify_hmac' => false, 'verify_ip_allowlist' => false, 'ingest_response_code' => 200,
            'signing_enabled' => true, 'created_at' => '2026-01-02T03:04:05Z', 'updated_at' => '2026-01-02T03:04:05Z',
        ];
    }

    public function testGetInboundEndpointMapsIngestUrl(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => $this->inboundEndpointData() + ['ingest_url' => 'https://ingest']])], $history);

        self::assertSame('https://ingest', $client->getInboundEndpoint('ib_1')->ingestUrl);
    }

    public function testGetInboundEndpointWithoutIngestUrl(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => $this->inboundEndpointData()])], $history);

        self::assertNull($client->getInboundEndpoint('ib_1')->ingestUrl);
    }

    public function testInboundMetricsMapsAvgQueueWaitMs(): void
    {
        $history = [];
        $data = $this->metricsData() + ['avg_delivery_time_ms' => 3];
        $client = $this->makeClient([$this->json(['data' => $data + ['avg_queue_wait_ms' => 7]]), $this->json(['data' => $data])], $history);

        self::assertSame(7, $client->getInboundMetrics()->avgQueueWaitMs);
        self::assertNull($client->getInboundMetrics()->avgQueueWaitMs);
    }

    public function testGetMessageMapsFailedAt(): void
    {
        $history = [];
        $base = [
            'id' => 'm1', 'project_id' => 'p', 'endpoint_id' => 'ep_1', 'status' => 'failed_permanent', 'attempt_count' => 3,
            'replay_count' => 0, 'content_type' => 'application/json', 'size_bytes' => 2, 'payload_sha256' => 'x',
            'created_at' => '2026-01-02T03:04:05Z', 'updated_at' => '2026-01-02T03:04:05Z',
        ];
        $client = $this->makeClient([
            $this->json(['data' => $base + ['failed_at' => '2026-01-04T00:00:00Z']]),
            $this->json(['data' => $base]),
        ], $history);

        self::assertSame('2026-01-04', $client->getMessage('m1')->failedAt->format('Y-m-d'));
        self::assertNull($client->getMessage('m1')->failedAt);
    }

    private function pullEndpointData(): array
    {
        return [
            'id' => 'pe_1', 'ingest_url' => 'https://i', 'active' => true, 'paused' => false,
            'created_at' => '2026-01-02T03:04:05Z', 'updated_at' => '2026-01-02T03:04:05Z', 'name' => 'full',
        ];
    }

    public function testUpdatePullEndpointFallsBackToGetWhenResponseIsPartial(): void
    {
        $history = [];
        $client = $this->makeClient([
            $this->json(['data' => ['id' => 'pe_1']]),
            $this->json(['data' => $this->pullEndpointData()]),
        ], $history);

        $result = $client->updatePullEndpoint('pe_1', ['name' => 'full']);

        self::assertCount(2, $history);
        self::assertSame('GET', $history[1]['request']->getMethod());
        self::assertSame('/v1/pull-endpoints/pe_1', $history[1]['request']->getUri()->getPath());
        self::assertSame('full', $result->name);
    }

    public function testUpdatePullEndpointUsesFullResponseWithoutRefetch(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => $this->pullEndpointData()])], $history);

        $client->updatePullEndpoint('pe_1', ['name' => 'full']);

        self::assertCount(1, $history);
    }

    public function testInboundTimeseriesWithoutBuckets(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => ['window' => '24h']])], $history);

        self::assertSame([], $client->getInboundTimeseriesMetrics()->buckets);
    }

    public function testSendEncodesEmptyPayloadAndHeadersAsObjects(): void
    {
        $history = [];
        $sendHistory = [];
        $client = $this->makeClient([], $history, [], [$this->json(['data' => ['message_id' => 'm1', 'status' => 'pending']])], $sendHistory);

        $client->send('ep_1', [], []);

        $decoded = json_decode($this->body($sendHistory), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded->payload);
        self::assertInstanceOf(\stdClass::class, $decoded->headers);
    }

    public function testSendLeavesNonEmptyPayloadUnchanged(): void
    {
        $history = [];
        $sendHistory = [];
        $client = $this->makeClient([], $history, [], [$this->json(['data' => ['message_id' => 'm1', 'status' => 'pending']])], $sendHistory);

        $client->send('ep_1', ['a' => 1], ['X-A' => 'b']);

        self::assertSame(['a' => 1], json_decode($this->body($sendHistory), true)['payload']);
        self::assertSame(['X-A' => 'b'], json_decode($this->body($sendHistory), true)['headers']);
    }

    public function testCreateAndUpdateEndpointEncodeEmptyHeadersAsObject(): void
    {
        $history = [];
        $client = $this->makeClient([
            $this->json(['data' => ['id' => 'ep_1', 'url' => 'https://e', 'signing_secret' => 's', 'created_at' => '2026-01-02T03:04:05Z']]),
            $this->json(['data' => ['id' => 'ep_1', 'updated' => true]]),
            $this->json(['data' => ['id' => 'ep_1', 'url' => 'https://e', 'signing_secret' => 's', 'created_at' => '2026-01-02T03:04:05Z']]),
            $this->json(['data' => ['id' => 'ep_1', 'updated' => true]]),
        ], $history);

        $client->createEndpoint('https://e', headers: []);
        $client->updateEndpoint('ep_1', headers: []);
        $client->createEndpoint('https://e', headers: ['X-A' => 'b']);
        $client->updateEndpoint('ep_1', headers: ['X-A' => 'b']);

        self::assertInstanceOf(\stdClass::class, json_decode($this->body($history, 0), false)->headers);
        self::assertInstanceOf(\stdClass::class, json_decode($this->body($history, 1), false)->headers);
        self::assertSame(['X-A' => 'b'], json_decode($this->body($history, 2), true)['headers']);
        self::assertSame(['X-A' => 'b'], json_decode($this->body($history, 3), true)['headers']);
    }
}
