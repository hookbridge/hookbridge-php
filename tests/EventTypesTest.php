<?php

declare(strict_types=1);

namespace HookBridge\Tests;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use HookBridge\Exception\HookBridgeException;
use HookBridge\Exception\NotFoundException;
use HookBridge\Exception\RateLimitException;
use HookBridge\HookBridge;
use HookBridge\Response\EventSubscriptions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EventTypesTest extends TestCase
{
    private const EP = 'ep_550e8400e29b41d4a716446655440000';

    private function makeClient(array $management, array $send, array &$managementHistory, array &$sendHistory): HookBridge
    {
        $client = new HookBridge('hb_test_mock_key', retries: 0);

        $managementStack = HandlerStack::create(new MockHandler($management));
        $managementStack->push(Middleware::history($managementHistory));
        $sendStack = HandlerStack::create(new MockHandler($send));
        $sendStack->push(Middleware::history($sendHistory));

        $reflection = new ReflectionClass($client);
        $reflection->getProperty('client')->setValue($client, new Client(['base_uri' => 'https://api.hookbridge.io', 'handler' => $managementStack]));
        $reflection->getProperty('sendClient')->setValue($client, new Client(['base_uri' => 'https://send.hookbridge.io', 'handler' => $sendStack]));

        return $client;
    }

    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function body(array $history, int $index = 0): array
    {
        return json_decode((string) $history[$index]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function eventTypeData(array $overrides = []): array
    {
        return $overrides + [
            'id' => '01935abc-def0-7123-4567-890abcdef012',
            'name' => 'order.created',
            'description' => 'Sent when an order is placed',
            'group' => 'orders',
            'schema' => ['type' => 'object'],
            'sample_payload' => ['order_id' => 'x'],
            'deprecated' => false,
            'public' => true,
            'auto_created' => false,
            'archived_at' => null,
            'created_at' => '2026-06-01T00:00:00Z',
            'updated_at' => '2026-06-02T00:00:00Z',
        ];
    }

    public function testSendEventWithoutEndpointUsesSendHost(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([], [
            $this->json(['data' => ['event_id' => 'evt_1', 'event_type' => 'order.created', 'status' => 'accepted']], 202),
        ], $mgmt, $send);

        $result = $client->sendEvent('order.created', ['order_id' => '1']);

        self::assertSame('evt_1', $result->eventId);
        self::assertSame('order.created', $result->eventType);
        self::assertSame('accepted', $result->status);
        self::assertNull($result->messageId);
        self::assertCount(0, $mgmt);
        self::assertSame('send.hookbridge.io', $send[0]['request']->getUri()->getHost());
        self::assertSame('/v1/webhooks/send', $send[0]['request']->getUri()->getPath());
        self::assertSame(['event_type' => 'order.created', 'payload' => ['order_id' => '1']], $this->body($send));
    }

    public function testSendEventWithAllOptions(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([], [
            $this->json(['data' => ['event_id' => 'evt_1', 'event_type' => 'order.created', 'status' => 'accepted', 'message_id' => 'msg_1']], 202),
        ], $mgmt, $send);

        $result = $client->sendEvent(
            'order.created',
            ['order_id' => '1'],
            endpointId: self::EP,
            headers: ['X-Tenant' => 't'],
            idempotencyKey: 'key-1',
            retentionHours: 24,
        );

        self::assertSame('msg_1', $result->messageId);
        self::assertSame([
            'event_type' => 'order.created',
            'payload' => ['order_id' => '1'],
            'endpoint_id' => self::EP,
            'headers' => ['X-Tenant' => 't'],
            'idempotency_key' => 'key-1',
            'retention_hours' => 24,
        ], $this->body($send));
    }

    public function testExistingSendSignatureUnchanged(): void
    {
        $params = array_map(fn($p) => $p->getName(), (new \ReflectionMethod(HookBridge::class, 'send'))->getParameters());
        self::assertSame(['endpointId', 'payload', 'headers', 'idempotencyKey', 'retentionHours'], $params);
    }

    public function testCreateEventType(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([$this->json(['data' => $this->eventTypeData()], 201)], [], $mgmt, $send);

        $type = $client->createEventType(
            'order.created',
            description: 'Sent when an order is placed',
            group: 'orders',
            schema: ['type' => 'object'],
            samplePayload: ['order_id' => 'x'],
            deprecated: false,
            public: true,
        );

        self::assertSame('POST', $mgmt[0]['request']->getMethod());
        self::assertSame('/v1/event-types', $mgmt[0]['request']->getUri()->getPath());
        self::assertSame([
            'name' => 'order.created',
            'description' => 'Sent when an order is placed',
            'group' => 'orders',
            'schema' => ['type' => 'object'],
            'sample_payload' => ['order_id' => 'x'],
            'deprecated' => false,
            'public' => true,
        ], $this->body($mgmt));
        self::assertSame('order.created', $type->name);
        self::assertSame('orders', $type->group);
        self::assertTrue($type->public);
        self::assertFalse($type->autoCreated);
        self::assertNull($type->archivedAt);
        self::assertSame(['order_id' => 'x'], $type->samplePayload);
        self::assertInstanceOf(DateTimeImmutable::class, $type->createdAt);
    }

    public function testCreateEventTypeNameOnlyOmitsOptionalFields(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([$this->json(['data' => $this->eventTypeData()], 201)], [], $mgmt, $send);

        $client->createEventType('order.created');

        self::assertSame(['name' => 'order.created'], $this->body($mgmt));
    }

    public function testListEventTypes(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['data' => [$this->eventTypeData(['archived_at' => '2026-06-03T00:00:00Z'])], 'meta' => ['next_cursor' => 'cur2']]),
        ], [], $mgmt, $send);

        $result = $client->listEventTypes(limit: 10, cursor: 'cur1', group: 'orders', namePrefix: 'order.', includeArchived: true);

        parse_str($mgmt[0]['request']->getUri()->getQuery(), $query);
        self::assertSame(['limit' => '10', 'cursor' => 'cur1', 'group' => 'orders', 'name_prefix' => 'order.', 'include_archived' => 'true'], $query);
        self::assertCount(1, $result->eventTypes);
        self::assertInstanceOf(DateTimeImmutable::class, $result->eventTypes[0]->archivedAt);
        self::assertTrue($result->hasMore);
        self::assertSame('cur2', $result->nextCursor);
    }

    public function testListEventTypesLastPage(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([$this->json(['data' => [], 'meta' => ['request_id' => 'r']])], [], $mgmt, $send);

        $result = $client->listEventTypes();

        self::assertSame('', $mgmt[0]['request']->getUri()->getQuery());
        self::assertFalse($result->hasMore);
        self::assertNull($result->nextCursor);
    }

    public function testEventTypePathsEncodeWildcardAndDots(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['data' => $this->eventTypeData(['name' => 'order.*'])]),
            $this->json(['data' => $this->eventTypeData()]),
            $this->json(['data' => $this->eventTypeData(['archived_at' => '2026-06-03T00:00:00Z'])]),
            $this->json(['data' => $this->eventTypeData()]),
        ], [], $mgmt, $send);

        $client->getEventType('order.*');
        $client->updateEventType('order.created', ['description' => 'x', 'group' => null]);
        $client->archiveEventType('order.created');
        $client->unarchiveEventType('order.created');

        self::assertSame('GET', $mgmt[0]['request']->getMethod());
        self::assertSame('/v1/event-types/order.%2A', $mgmt[0]['request']->getUri()->getPath());
        self::assertSame('PATCH', $mgmt[1]['request']->getMethod());
        self::assertSame('/v1/event-types/order.created', $mgmt[1]['request']->getUri()->getPath());
        self::assertSame(['description' => 'x', 'group' => null], $this->body($mgmt, 1));
        self::assertSame('DELETE', $mgmt[2]['request']->getMethod());
        self::assertSame('/v1/event-types/order.created', $mgmt[2]['request']->getUri()->getPath());
        self::assertSame('POST', $mgmt[3]['request']->getMethod());
        self::assertSame('/v1/event-types/order.created/unarchive', $mgmt[3]['request']->getUri()->getPath());
    }

    public function testListEventTypeGroups(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([$this->json(['data' => [['name' => 'orders', 'count' => 4]]])], [], $mgmt, $send);

        $groups = $client->listEventTypeGroups();

        self::assertSame('/v1/event-type-groups', $mgmt[0]['request']->getUri()->getPath());
        self::assertSame('orders', $groups[0]->name);
        self::assertSame(4, $groups[0]->count);
    }

    private function eventData(array $overrides = []): array
    {
        return $overrides + [
            'id' => '01935abc-def0-7123-4567-890abcdef012',
            'project_id' => '01935abc-def0-7123-4567-890abcdef099',
            'event_type' => 'order.created',
            'payload_sha256' => 'abc',
            'content_type' => 'application/json',
            'size_bytes' => 12,
            'fanout_status' => 'done',
            'matched_count' => 2,
            'created_at' => '2026-06-01T00:00:00Z',
            'payload_available' => true,
        ];
    }

    public function testListEvents(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['data' => [$this->eventData()], 'meta' => ['has_more' => true, 'next_cursor' => 'c2']]),
        ], [], $mgmt, $send);

        $result = $client->listEvents(
            limit: 5,
            cursor: 'c1',
            eventType: 'order.created',
            since: new DateTimeImmutable('2026-06-01T00:00:00+00:00'),
            until: '2026-06-02T00:00:00Z',
        );

        parse_str($mgmt[0]['request']->getUri()->getQuery(), $query);
        self::assertSame([
            'limit' => '5',
            'cursor' => 'c1',
            'event_type' => 'order.created',
            'since' => '2026-06-01T00:00:00+00:00',
            'until' => '2026-06-02T00:00:00Z',
        ], $query);
        self::assertSame('/v1/events', $mgmt[0]['request']->getUri()->getPath());
        self::assertSame(2, $result->events[0]->matchedCount);
        self::assertSame('done', $result->events[0]->fanoutStatus);
        self::assertTrue($result->hasMore);
        self::assertSame('c2', $result->nextCursor);
    }

    public function testGetEvent(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['data' => $this->eventData([
                'target_endpoint_id' => 'e1',
                'headers' => ['X-A' => 'b'],
                'fanned_out_at' => '2026-06-01T00:00:01Z',
                'payload_url' => 'https://example.com/p',
                'messages' => [[
                    'id' => 'm1',
                    'endpoint_id' => 'e1',
                    'status' => 'queued',
                    'attempt_count' => 1,
                    'next_attempt_at' => '2026-06-01T00:01:00Z',
                    'created_at' => '2026-06-01T00:00:01Z',
                ]],
                'messages_has_more' => false,
            ])]),
        ], [], $mgmt, $send);

        $event = $client->getEvent('evt_1', messagesLimit: 10, messagesCursor: 'mc');

        self::assertSame('/v1/events/evt_1', $mgmt[0]['request']->getUri()->getPath());
        parse_str($mgmt[0]['request']->getUri()->getQuery(), $query);
        self::assertSame(['messages_limit' => '10', 'messages_cursor' => 'mc'], $query);
        self::assertSame('done', $event->fanoutStatus);
        self::assertSame(2, $event->matchedCount);
        self::assertSame('https://example.com/p', $event->payloadUrl);
        self::assertSame(['X-A' => 'b'], $event->headers);
        self::assertCount(1, $event->messages);
        self::assertSame('m1', $event->messages[0]->id);
        self::assertSame(1, $event->messages[0]->attemptCount);
        self::assertFalse($event->messagesHasMore);
        self::assertNull($event->messagesNextCursor);
    }

    public function testDeleteEventAndNotFound(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            new Response(204),
            $this->json(['error' => ['code' => 'EVENT_NOT_FOUND', 'message' => 'nope']], 404),
        ], [], $mgmt, $send);

        $client->deleteEvent('evt_1');
        self::assertSame('DELETE', $mgmt[0]['request']->getMethod());
        self::assertSame('/v1/events/evt_1', $mgmt[0]['request']->getUri()->getPath());

        $this->expectException(NotFoundException::class);
        $client->getEvent('evt_1');
    }

    public function testEndpointSubscriptionsSerializedOnlyWhenSet(): void
    {
        $mgmt = [];
        $send = [];
        $created = ['id' => self::EP, 'url' => 'https://x.test', 'signing_secret' => 's', 'created_at' => '2026-06-01T00:00:00Z', 'subscriptions' => ['event_types' => ['a.b'], 'groups' => []]];
        $full = ['id' => self::EP, 'url' => 'https://x.test', 'paused' => false, 'created_at' => '2026-06-01T00:00:00Z', 'updated_at' => '2026-06-01T00:00:00Z', 'subscriptions' => ['event_types' => ['a.*'], 'groups' => ['g']]];
        $client = $this->makeClient([
            $this->json(['data' => $created], 201),
            $this->json(['data' => $full]),
            $this->json(['data' => $full]),
            $this->json(['data' => $full]),
        ], [], $mgmt, $send);

        $createdResponse = $client->createEndpoint('https://x.test', subscriptions: new EventSubscriptions(eventTypes: ['a.b']));
        $client->updateEndpoint(self::EP, description: 'd');
        $client->updateEndpoint(self::EP, subscriptions: new EventSubscriptions(eventTypes: [], groups: []));
        $fetched = $client->getEndpoint(self::EP);

        self::assertSame(['a.b'], $createdResponse->subscriptions->eventTypes);
        self::assertSame(['event_types' => ['a.b'], 'groups' => []], $this->body($mgmt)['subscriptions']);
        self::assertArrayNotHasKey('subscriptions', $this->body($mgmt, 1));
        self::assertSame('{"event_types":[],"groups":[]}', json_encode(json_decode((string) $mgmt[2]['request']->getBody())->subscriptions));
        self::assertSame(['a.*'], $fetched->subscriptions->eventTypes);
        self::assertSame(['g'], $fetched->subscriptions->groups);
    }

    public function testEndpointWithoutSubscriptionsParsesToNull(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['data' => ['id' => self::EP, 'url' => 'https://x.test', 'paused' => false, 'created_at' => '2026-06-01T00:00:00Z', 'updated_at' => '2026-06-01T00:00:00Z']]),
        ], [], $mgmt, $send);

        self::assertNull($client->getEndpoint(self::EP)->subscriptions);
    }

    public function testListEndpointsEventTypeFilter(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['data' => [['id' => self::EP, 'url' => 'https://x.test', 'paused' => false, 'created_at' => '2026-06-01T00:00:00Z', 'subscriptions' => ['event_types' => ['order.*'], 'groups' => []]]], 'meta' => []]),
        ], [], $mgmt, $send);

        $result = $client->listEndpoints(eventType: 'order.created');

        parse_str($mgmt[0]['request']->getUri()->getQuery(), $query);
        self::assertSame(['event_type' => 'order.created'], $query);
        self::assertSame(['order.*'], $result->endpoints[0]->subscriptions->eventTypes);
    }

    public function testRecoverEndpointAndGetRecoverJob(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['data' => ['recover_job_id' => 'job_1', 'status' => 'pending']], 202),
            $this->json(['data' => [
                'recover_job_id' => 'job_1',
                'endpoint_id' => self::EP,
                'status' => 'done',
                'created_count' => 3,
                'requeued_count' => 1,
                'since' => '2026-06-01T00:00:00Z',
                'until' => '2026-06-02T00:00:00Z',
                'created_at' => '2026-06-02T00:00:00Z',
                'finished_at' => '2026-06-02T00:00:05Z',
            ]]),
        ], [], $mgmt, $send);

        $job = $client->recoverEndpoint(self::EP, new DateTimeImmutable('2026-06-01T00:00:00+00:00'), '2026-06-02T00:00:00Z');
        $status = $client->getRecoverJob(self::EP, 'job_1');

        self::assertSame('POST', $mgmt[0]['request']->getMethod());
        self::assertSame('/v1/endpoints/' . self::EP . '/recover', $mgmt[0]['request']->getUri()->getPath());
        self::assertSame(['since' => '2026-06-01T00:00:00+00:00', 'until' => '2026-06-02T00:00:00Z'], $this->body($mgmt));
        self::assertSame('job_1', $job->recoverJobId);
        self::assertSame('pending', $job->status);
        self::assertSame('/v1/endpoints/' . self::EP . '/recover/job_1', $mgmt[1]['request']->getUri()->getPath());
        self::assertSame('done', $status->status);
        self::assertSame(3, $status->createdCount);
        self::assertSame(1, $status->requeuedCount);
        self::assertInstanceOf(DateTimeImmutable::class, $status->finishedAt);
    }

    public function testRecoverEndpointOmitsUntilByDefault(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([$this->json(['data' => ['recover_job_id' => 'job_1', 'status' => 'pending']], 202)], [], $mgmt, $send);

        $client->recoverEndpoint(self::EP, '2026-06-01T00:00:00Z');

        self::assertSame(['since' => '2026-06-01T00:00:00Z'], $this->body($mgmt));
    }

    public function testSendTestEvent(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['data' => ['message_id' => 'm1', 'status' => 'queued', 'event_type' => 'order.created', 'is_test' => true]], 202),
            $this->json(['data' => ['message_id' => 'm2', 'status' => 'queued', 'event_type' => 'order.created', 'is_test' => true]], 202),
        ], [], $mgmt, $send);

        $withPayload = $client->sendTestEvent(self::EP, 'order.created', ['a' => 1]);
        $client->sendTestEvent(self::EP, 'order.created');

        self::assertSame('/v1/endpoints/' . self::EP . '/test', $mgmt[0]['request']->getUri()->getPath());
        self::assertSame(['event_type' => 'order.created', 'payload' => ['a' => 1]], $this->body($mgmt));
        self::assertSame(['event_type' => 'order.created'], $this->body($mgmt, 1));
        self::assertSame('m1', $withPayload->messageId);
        self::assertSame('queued', $withPayload->status);
        self::assertSame('order.created', $withPayload->eventType);
        self::assertTrue($withPayload->isTest);
    }

    public function testPlanLimit429IsNotARateLimit(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['error' => ['code' => 'EVENT_TYPE_LIMIT_REACHED', 'message' => 'limit', 'details' => ['limit' => 1000]], 'meta' => ['request_id' => 'req_1']], 429),
        ], [], $mgmt, $send);

        try {
            $client->createEventType('order.created');
            self::fail('expected exception');
        } catch (RateLimitException) {
            self::fail('plan limit must not be a RateLimitException');
        } catch (HookBridgeException $e) {
            self::assertSame('EVENT_TYPE_LIMIT_REACHED', $e->errorCode);
            self::assertSame(429, $e->statusCode);
            self::assertSame(['limit' => 1000], $e->details);
            self::assertSame('req_1', $e->requestId);
        }
    }

    public function testPlanLimit429OnSendIsNotARateLimit(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([], [
            $this->json(['error' => ['code' => 'EVENT_TYPE_LIMIT_REACHED', 'message' => 'limit']], 429),
        ], $mgmt, $send);

        $this->expectException(HookBridgeException::class);
        try {
            $client->sendEvent('order.created', []);
        } catch (RateLimitException) {
            self::fail('plan limit must not be a RateLimitException');
        }
    }

    public function testNormal429StillMapsToRateLimit(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            new Response(429, ['Retry-After' => '7'], json_encode(['error' => ['code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'slow down']], JSON_THROW_ON_ERROR)),
        ], [], $mgmt, $send);

        try {
            $client->sendTestEvent(self::EP, 'order.created');
            self::fail('expected exception');
        } catch (RateLimitException $e) {
            self::assertSame(7, $e->retryAfter);
        }
    }

    public function testConflictCodesSurface(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['error' => ['code' => 'EVENT_TYPE_EXISTS', 'message' => 'exists']], 409),
            $this->json(['error' => ['code' => 'RECOVER_JOB_ACTIVE', 'message' => 'active']], 409),
        ], [], $mgmt, $send);

        try {
            $client->createEventType('order.created');
            self::fail('expected exception');
        } catch (HookBridgeException $e) {
            self::assertSame('EVENT_TYPE_EXISTS', $e->errorCode);
            self::assertSame(409, $e->statusCode);
        }

        try {
            $client->recoverEndpoint(self::EP, '2026-06-01T00:00:00Z');
            self::fail('expected exception');
        } catch (HookBridgeException $e) {
            self::assertSame('RECOVER_JOB_ACTIVE', $e->errorCode);
            self::assertSame(409, $e->statusCode);
        }
    }

    public function testNullSubscriptionListsBecomeEmpty(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['data' => ['id' => self::EP, 'url' => 'https://x.test', 'paused' => false, 'created_at' => '2026-06-01T00:00:00Z', 'updated_at' => '2026-06-01T00:00:00Z', 'subscriptions' => ['event_types' => null, 'groups' => null]]]),
        ], [], $mgmt, $send);

        $subscriptions = $client->getEndpoint(self::EP)->subscriptions;

        self::assertSame([], $subscriptions->eventTypes);
        self::assertSame([], $subscriptions->groups);
    }

    public function testListEndpointsEmptyNextCursorMeansLastPage(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([$this->json(['data' => [], 'meta' => ['next_cursor' => '']])], [], $mgmt, $send);

        $result = $client->listEndpoints(eventType: 'order.created');

        self::assertFalse($result->hasMore);
        self::assertNull($result->nextCursor);
    }

    public function testEmptySubscriptionsSerializeAsEmptyLists(): void
    {
        $mgmt = [];
        $send = [];
        $full = ['id' => self::EP, 'url' => 'https://x.test', 'paused' => false, 'created_at' => '2026-06-01T00:00:00Z', 'updated_at' => '2026-06-01T00:00:00Z'];
        $client = $this->makeClient([$this->json(['data' => $full])], [], $mgmt, $send);

        $client->updateEndpoint(self::EP, subscriptions: new EventSubscriptions());

        self::assertSame('{"subscriptions":{"event_types":[],"groups":[]}}', (string) $mgmt[0]['request']->getBody());
    }

    public function testEmptyArraysEncodeAsJsonObjects(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['data' => $this->eventTypeData()], 201),
            $this->json(['data' => $this->eventTypeData()]),
            $this->json(['data' => ['message_id' => 'm1', 'status' => 'queued', 'event_type' => 'a', 'is_test' => true]], 202),
        ], [
            $this->json(['data' => ['event_id' => 'e', 'event_type' => 'a', 'status' => 'accepted']], 202),
        ], $mgmt, $send);

        $client->sendEvent('a', []);
        $client->createEventType('a', schema: [], samplePayload: []);
        $client->updateEventType('a', ['schema' => [], 'sample_payload' => [], 'group' => null]);
        $client->sendTestEvent(self::EP, 'a', []);

        self::assertSame('{"event_type":"a","payload":{}}', (string) $send[0]['request']->getBody());
        self::assertSame('{"name":"a","schema":{},"sample_payload":{}}', (string) $mgmt[0]['request']->getBody());
        self::assertSame('{"schema":{},"sample_payload":{},"group":null}', (string) $mgmt[1]['request']->getBody());
        self::assertSame('{"event_type":"a","payload":{}}', (string) $mgmt[2]['request']->getBody());
    }

    public function testEndpointIdIsEncodedInRecoverAndTestPaths(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([
            $this->json(['data' => ['recover_job_id' => 'j', 'status' => 'pending']], 202),
            $this->json(['data' => ['message_id' => 'm1', 'status' => 'queued', 'event_type' => 'a', 'is_test' => true]], 202),
        ], [], $mgmt, $send);

        $client->recoverEndpoint('a/b', '2026-06-01T00:00:00Z');
        $client->sendTestEvent('a/b', 'a');

        self::assertSame('/v1/endpoints/a%2Fb/recover', $mgmt[0]['request']->getUri()->getPath());
        self::assertSame('/v1/endpoints/a%2Fb/test', $mgmt[1]['request']->getUri()->getPath());
    }

    public function testGetRecoverJobEncodesEndpointId(): void
    {
        $mgmt = [];
        $send = [];
        $client = $this->makeClient([$this->json(['data' => [
            'recover_job_id' => 'j', 'endpoint_id' => 'x', 'status' => 'done', 'created_count' => 0, 'requeued_count' => 0,
            'since' => '2026-06-01T00:00:00Z', 'until' => '2026-06-02T00:00:00Z', 'created_at' => '2026-06-02T00:00:00Z',
        ]])], [], $mgmt, $send);

        $client->getRecoverJob('a/b', 'j');

        self::assertSame('/v1/endpoints/a%2Fb/recover/j', $mgmt[0]['request']->getUri()->getPath());
    }
}
