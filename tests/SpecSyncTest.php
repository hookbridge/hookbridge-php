<?php

declare(strict_types=1);

namespace HookBridge\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use HookBridge\Exception\ValidationException;
use HookBridge\HookBridge;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SpecSyncTest extends TestCase
{
    private const EVENT_ID = '01935abc-def0-7123-4567-890abcdef012';

    private function makeClient(array $management, array &$history): HookBridge
    {
        $client = new HookBridge('hb_test_mock_key', retries: 0);
        $stack = HandlerStack::create(new MockHandler($management));
        $stack->push(Middleware::history($history));
        $reflection = new ReflectionClass($client);
        $reflection->getProperty('client')->setValue($client, new Client(['base_uri' => 'https://api.hookbridge.io', 'handler' => $stack]));

        return $client;
    }

    private function json(array $body): Response
    {
        return new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function messageData(array $extra = []): array
    {
        return $extra + [
            'id' => 'msg_1',
            'project_id' => 'proj_1',
            'endpoint_id' => 'ep_550e8400e29b41d4a716446655440000',
            'status' => 'succeeded',
            'attempt_count' => 1,
            'replay_count' => 0,
            'content_type' => 'application/json',
            'size_bytes' => 10,
            'payload_sha256' => 'abc',
            'created_at' => '2026-06-01T00:00:00Z',
            'updated_at' => '2026-06-01T00:00:00Z',
        ];
    }

    private function eventData(array $extra = []): array
    {
        return $extra + [
            'id' => self::EVENT_ID,
            'project_id' => 'proj_1',
            'event_type' => 'order.created',
            'payload_sha256' => 'abc',
            'content_type' => 'application/json',
            'size_bytes' => 10,
            'fanout_status' => 'completed',
            'matched_count' => 2,
            'created_at' => '2026-06-01T00:00:00Z',
            'payload_available' => true,
        ];
    }

    public function testMessageEventInfo(): void
    {
        $history = [];
        $client = $this->makeClient([
            $this->json(['data' => $this->messageData(['event_type' => 'order.created', 'event_id' => self::EVENT_ID, 'is_test' => true])]),
            $this->json(['data' => $this->messageData()]),
        ], $history);

        $typed = $client->getMessage('msg_1');
        self::assertSame('order.created', $typed->eventType);
        self::assertSame(self::EVENT_ID, $typed->eventId);
        self::assertTrue($typed->isTest);

        $untyped = $client->getMessage('msg_1');
        self::assertNull($untyped->eventType);
        self::assertNull($untyped->eventId);
        self::assertNull($untyped->isTest);
    }

    public function testLogsEventInfoAndEndpointFilter(): void
    {
        $history = [];
        $client = $this->makeClient([
            $this->json(['data' => [
                ['message_id' => 'msg_1', 'endpoint' => 'ep_1', 'status' => 'succeeded', 'attempt_count' => 1, 'created_at' => '2026-06-01T00:00:00Z', 'event_type' => 'order.created', 'event_id' => self::EVENT_ID, 'is_test' => false],
                ['message_id' => 'msg_2', 'endpoint' => 'ep_1', 'status' => 'succeeded', 'attempt_count' => 1, 'created_at' => '2026-06-01T00:00:00Z'],
            ], 'meta' => ['has_more' => false]]),
        ], $history);

        $logs = $client->getLogs(endpointId: 'ep_550e8400e29b41d4a716446655440000');

        self::assertSame('endpoint_id=ep_550e8400e29b41d4a716446655440000', $history[0]['request']->getUri()->getQuery());
        self::assertSame('order.created', $logs->messages[0]->eventType);
        self::assertSame(self::EVENT_ID, $logs->messages[0]->eventId);
        self::assertFalse($logs->messages[0]->isTest);
        self::assertNull($logs->messages[1]->eventType);
        self::assertNull($logs->messages[1]->isTest);
    }

    public function testEventStatusCounts(): void
    {
        $history = [];
        $client = $this->makeClient([
            $this->json(['data' => [$this->eventData(['status_counts' => ['succeeded' => 1, 'deleted' => 1]]), $this->eventData()], 'meta' => ['has_more' => false]]),
            $this->json(['data' => $this->eventData(['status_counts' => ['succeeded' => 2], 'messages' => [], 'messages_has_more' => false])]),
            $this->json(['data' => $this->eventData(['messages' => [], 'messages_has_more' => false])]),
        ], $history);

        $list = $client->listEvents();
        self::assertSame(['succeeded' => 1, 'deleted' => 1], $list->events[0]->statusCounts);
        self::assertSame([], $list->events[1]->statusCounts);

        self::assertSame(['succeeded' => 2], $client->getEvent(self::EVENT_ID)->statusCounts);
        self::assertSame([], $client->getEvent(self::EVENT_ID)->statusCounts);
    }

    public function testClientNameHeader(): void
    {
        $client = new HookBridge('hb_test_mock_key', clientName: 'my-tool/1.0');
        $reflection = new ReflectionClass($client);
        foreach (['client', 'sendClient'] as $property) {
            $headers = $reflection->getProperty($property)->getValue($client)->getConfig('headers');
            self::assertSame('my-tool/1.0', $headers['X-Hookbridge-Client']);
        }
    }

    public function testClientNameOmittedWhenEmpty(): void
    {
        foreach ([null, ''] as $name) {
            $client = new HookBridge('hb_test_mock_key', clientName: $name);
            $headers = (new ReflectionClass($client))->getProperty('client')->getValue($client)->getConfig('headers');
            self::assertArrayNotHasKey('X-Hookbridge-Client', $headers);
        }
    }

    public function testClientNameRejectsUnsafeValues(): void
    {
        foreach (["a\r\nX-Evil: 1", "a\nb", "a\rb", "caf\u{e9}", "a\x00b", "a\x7fb", "abc\n", "abc\r\n", "abc\r"] as $bad) {
            try {
                new HookBridge('hb_test_mock_key', clientName: $bad);
                self::fail('expected ValidationException');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
}
