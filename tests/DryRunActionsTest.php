<?php

declare(strict_types=1);

namespace HookBridge\Tests;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use HookBridge\Exception\NotFoundException;
use HookBridge\HookBridge;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DryRunActionsTest extends TestCase
{
    private function makeClient(array $responses, array &$history): HookBridge
    {
        $client = new HookBridge('hb_test_mock_key', retries: 0);

        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));
        $management = new Client(['base_uri' => 'https://api.hookbridge.io', 'handler' => $handler]);

        $prop = (new ReflectionClass($client))->getProperty('client');
        $prop->setValue($client, $management);

        return $client;
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function assertRequest(array $history, string $method, string $path, string $rawQuery): void
    {
        $request = $history[0]['request'];
        self::assertSame($method, $request->getMethod());
        self::assertSame($path, $request->getUri()->getPath());
        self::assertSame($rawQuery, $request->getUri()->getQuery());
    }

    public function testReplayAllMessagesDryRun(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => ['would_replay' => 5]])], $history);

        $result = $client->replayAllMessagesDryRun('failed_permanent', 'ep_1', 50);

        self::assertSame(5, $result->wouldReplay);
        $this->assertRequest($history, 'POST', '/v1/messages/replay-all', 'status=failed_permanent&endpoint_id=ep_1&limit=50&dry_run=true');
    }

    public function testReplayAllMessagesDryRunMinimal(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => ['would_replay' => 0]])], $history);

        $result = $client->replayAllMessagesDryRun('failed_permanent');

        self::assertSame(0, $result->wouldReplay);
        $this->assertRequest($history, 'POST', '/v1/messages/replay-all', 'status=failed_permanent&dry_run=true');
    }

    public function testReplayAllInboundMessagesDryRun(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => ['would_replay' => 7]])], $history);

        $result = $client->replayAllInboundMessagesDryRun('failed_permanent', 'ib_1', 10);

        self::assertSame(7, $result->wouldReplay);
        $this->assertRequest($history, 'POST', '/v1/inbound-messages/replay-all', 'status=failed_permanent&inbound_endpoint_id=ib_1&limit=10&dry_run=true');
    }

    public function testReplayAllInboundMessagesDryRunMinimal(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => ['would_replay' => 1]])], $history);

        $client->replayAllInboundMessagesDryRun('failed_permanent');

        $this->assertRequest($history, 'POST', '/v1/inbound-messages/replay-all', 'status=failed_permanent&dry_run=true');
    }

    public function testDryRunErrorRaisesException(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['error' => ['code' => 'not_found', 'message' => 'nope']], 404)], $history);

        $this->expectException(NotFoundException::class);
        $client->replayAllMessagesDryRun('failed_permanent');
    }

    private function actionRow(array $overrides = []): array
    {
        return array_merge([
            'id' => '01935abc-def0-7123-4567-890abcdef001',
            'action' => 'replay',
            'user_id' => 'user_1',
            'api_key_id' => null,
            'via' => 'console',
            'client_name' => null,
            'created_at' => '2026-01-02T03:04:05.123Z',
        ], $overrides);
    }

    public function testListMessageActionsMapsResponse(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json([
            'data' => [
                $this->actionRow(),
                $this->actionRow(['id' => 'x2', 'action' => 'cancel', 'user_id' => null, 'api_key_id' => 'key_1', 'via' => 'mcp-local', 'client_name' => 'Claude']),
            ],
            'meta' => ['has_more' => true, 'next_cursor' => 'cur_2'],
        ])], $history);

        $result = $client->listMessageActions('msg_1', 'cur_1', 2);

        $this->assertRequest($history, 'GET', '/v1/messages/msg_1/actions', 'cursor=cur_1&limit=2');
        self::assertTrue($result->hasMore);
        self::assertSame('cur_2', $result->nextCursor);
        self::assertCount(2, $result->actions);

        $first = $result->actions[0];
        self::assertSame('01935abc-def0-7123-4567-890abcdef001', $first->id);
        self::assertSame('replay', $first->action);
        self::assertSame('user_1', $first->userId);
        self::assertNull($first->apiKeyId);
        self::assertSame('console', $first->via);
        self::assertNull($first->clientName);
        self::assertInstanceOf(DateTimeImmutable::class, $first->createdAt);
        self::assertSame('2026-01-02T03:04:05.123', $first->createdAt->format('Y-m-d\TH:i:s.v'));

        $second = $result->actions[1];
        self::assertNull($second->userId);
        self::assertSame('key_1', $second->apiKeyId);
        self::assertSame('mcp-local', $second->via);
        self::assertSame('Claude', $second->clientName);
    }

    public function testListMessageActionsOmitsUnsetParamsAndEncodesId(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => [], 'meta' => ['has_more' => false]])], $history);

        $result = $client->listMessageActions('a/b');

        self::assertSame([], $result->actions);
        self::assertFalse($result->hasMore);
        self::assertNull($result->nextCursor);
        $request = $history[0]['request'];
        self::assertStringContainsString('/v1/messages/a%2Fb/actions', (string) $request->getUri());
        self::assertSame('', $request->getUri()->getQuery());
    }

    public function testListMessageActionsError(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['error' => ['code' => 'not_found', 'message' => 'nope']], 404)], $history);

        $this->expectException(NotFoundException::class);
        $client->listMessageActions('msg_1');
    }

    public function testListInboundMessageActions(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json([
            'data' => [$this->actionRow(['action' => 'dlq_replay', 'via' => 'api'])],
            'meta' => ['has_more' => false],
        ])], $history);

        $result = $client->listInboundMessageActions('in_1', limit: 5);

        $this->assertRequest($history, 'GET', '/v1/inbound-messages/in_1/actions', 'limit=5');
        self::assertCount(1, $result->actions);
        self::assertSame('dlq_replay', $result->actions[0]->action);
        self::assertSame('api', $result->actions[0]->via);
        self::assertNull($result->nextCursor);
    }

    public function testListInboundMessageActionsEncodesIdAndOmitsParams(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['data' => [], 'meta' => ['has_more' => false]])], $history);

        $client->listInboundMessageActions('a/b');

        $request = $history[0]['request'];
        self::assertStringContainsString('/v1/inbound-messages/a%2Fb/actions', (string) $request->getUri());
        self::assertSame('', $request->getUri()->getQuery());
    }

    public function testListInboundMessageActionsError(): void
    {
        $history = [];
        $client = $this->makeClient([$this->json(['error' => ['code' => 'not_found', 'message' => 'nope']], 404)], $history);

        $this->expectException(NotFoundException::class);
        $client->listInboundMessageActions('in_1');
    }
}
