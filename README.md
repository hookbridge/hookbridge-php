# HookBridge PHP SDK

Official PHP SDK for HookBridge. Send webhooks with guaranteed delivery, automatic retries, and inbound/outbound observability.

## Installation

```bash
composer require hookbridge/hookbridge-php
```

## Quick Start

```php
<?php

use HookBridge\HookBridge;

$client = new HookBridge('hb_live_xxxxxxxxxxxxxxxxxxxx');

$endpoint = $client->createEndpoint(
    url: 'https://customer.app/webhooks',
    description: 'Customer production webhook',
);

$result = $client->send(
    endpointId: $endpoint->id,
    payload: ['event' => 'order.created'],
);

echo $result->messageId . PHP_EOL;
```

## Outbound Endpoints

```php
$endpoint = $client->createEndpoint(
    url: 'https://customer.app/webhooks',
    description: 'Main production webhook',
    rateLimitRps: 10,
    burst: 20,
);

$details = $client->getEndpoint($endpoint->id);
$list = $client->listEndpoints(limit: 50);
$rotated = $client->rotateEndpointSecret($endpoint->id);
$client->deleteEndpoint($endpoint->id);
```

## Client Name

Pass `clientName` to send an `X-Hookbridge-Client` header on every request. The server records it in the audit trail of replay, retry, and cancel actions. It must be printable ASCII (the server truncates to 200 bytes) and is not authenticated; a value with other characters throws `ValidationException`. Replay, retry, cancel and replay-all/batch methods also accept a per-call `clientName` that overrides the client-level value for that request only. `deleteEndpoint()` and `deleteAPIKey()` return a `DeleteResult`; `replay()`, `cancelRetry()`, `retryNow()` and `replayFromDLQ()` return a `SendResponse`.

```php
$client = new HookBridge('hb_live_xxxxxxxxxxxxxxxxxxxx', clientName: 'billing-service/1.4');
```

## Sending and Observability

```php
$result = $client->send(
    endpointId: $endpoint->id,
    payload: ['event' => 'user.created', 'user_id' => 'usr_123'],
    idempotencyKey: 'user-123-created',
);

$message = $client->getMessage($result->messageId);
$logs = $client->getLogs(limit: 100);
$endpointLogs = $client->getLogs(endpointId: $endpoint->id);
$metrics = $client->getMetrics();
$timeseries = $client->getTimeseriesMetrics(endpointId: $endpoint->id);

// Messages and log entries sent from an event carry eventType, eventId and isTest (null otherwise)
echo $message->eventType;

$replayed = $client->replay($message->id); // SendResponse: messageId, status, message
$client->replay($message->id, clientName: 'ops-console'); // per-call X-Hookbridge-Client override (replay, cancelRetry, retryNow, replayFromDLQ, replayAll*/replayBatch*)
$endpointMetrics = $client->getMetrics('24h', $endpoint->id);
$client->replayAllMessages('failed_permanent', $endpoint->id, 50);

$preview = $client->replayAllMessagesDryRun('failed_permanent', $endpoint->id, 50);
echo $preview->wouldReplay;

$actions = $client->listMessageActions($message->id, limit: 25);
foreach ($actions->actions as $action) {
    echo "{$action->action} via {$action->via} at {$action->createdAt->format('c')}\n";
}
```

## Event Types

Register event types, subscribe endpoints to them, and send events by type. Wildcards like `order.*` are supported in subscriptions.

```php
use HookBridge\Response\EventSubscriptions;

$client->createEventType('order.created', group: 'orders', description: 'Order placed');

$endpoint = $client->createEndpoint(
    url: 'https://customer.app/webhooks',
    subscriptions: new EventSubscriptions(eventTypes: ['order.*']),
);
$client->updateEndpoint($endpoint->id, subscriptions: new EventSubscriptions(eventTypes: ['order.created']));
$subscribed = $client->listEndpoints(eventType: 'order.created');

$types = $client->listEventTypes(namePrefix: 'order.');
$client->updateEventType('order.created', ['description' => 'New description', 'group' => null]);
$client->archiveEventType('order.created');
$client->unarchiveEventType('order.created');
$groups = $client->listEventTypeGroups();
```

## Events, Recovery, and Test Events

```php
// Fan out to every subscribed endpoint (or pass endpointId: to target one)
$sent = $client->sendEvent('order.created', ['order_id' => 'ord_123']);

$event = $client->getEvent($sent->eventId);
echo "{$event->fanoutStatus}: {$event->matchedCount} endpoints\n";
print_r($event->statusCounts); // e.g. ['succeeded' => 2, 'deleted' => 1]
$events = $client->listEvents(eventType: 'order.created', since: '2026-06-01T00:00:00Z');
$client->deleteEvent($sent->eventId);

// Give an endpoint the events it missed
$job = $client->recoverEndpoint($endpoint->id, since: '2026-06-01T00:00:00Z');
$status = $client->getRecoverJob($endpoint->id, $job->recoverJobId);

// Check a receiver with the event type's sample payload (or pass a payload)
$client->sendTestEvent($endpoint->id, 'order.created');
```

## Inbound Webhooks

```php
$inbound = $client->createInboundEndpoint(
    url: 'https://myapp.com/webhooks/inbound',
    name: 'Stripe inbound',
    description: 'Receives Stripe events through HookBridge',
    verifyStaticToken: true,
    tokenHeaderName: 'X-Webhook-Token',
    tokenValue: 'my-shared-secret',
    signingEnabled: true,
    idempotencyHeaderNames: ['X-Idempotency-Key'],
    ingestResponseCode: 202,
);

echo $inbound->ingestUrl . PHP_EOL;   // Save this
echo $inbound->secretToken . PHP_EOL; // Only shown once

$details = $client->getInboundEndpoint($inbound->id);
$client->pauseInboundEndpoint($inbound->id);
$client->resumeInboundEndpoint($inbound->id);

$client->updateInboundEndpoint($inbound->id, [
    'verify_hmac' => true,
    'hmac_header_name' => 'X-Signature',
    'hmac_secret' => 'whsec_inbound_secret',
]);
```

## Inbound Observability

```php
$inboundEndpoints = $client->listInboundEndpoints(limit: 50);
$inboundLogs = $client->getInboundLogs(inboundEndpointId: $inbound->id, limit: 50);
$inboundMetrics = $client->getInboundMetrics(inboundEndpointId: $inbound->id);
$inboundTimeseries = $client->getInboundTimeseriesMetrics(inboundEndpointId: $inbound->id);
$rejections = $client->listInboundRejections(inboundEndpointId: $inbound->id, limit: 25);
$inboundPreview = $client->replayAllInboundMessagesDryRun('failed_permanent', $inbound->id, 50);
// Takes an inbound message ID, not the inbound endpoint ID
$inboundActions = $client->listInboundMessageActions($inboundMessageId, limit: 25);
```

## Billing and Exports

```php
$subscription = $client->getSubscription();
$usage = $client->getUsageHistory(limit: 12, offset: 0);
$invoices = $client->getInvoices(limit: 12);

$export = $client->createExport(
    startTime: new DateTimeImmutable('-24 hours'),
    endTime: new DateTimeImmutable('now'),
    endpointId: $endpoint->id,
);

$downloadUrl = $client->downloadExport($export->id);
```
