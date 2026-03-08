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

## Sending and Observability

```php
$result = $client->send(
    endpointId: $endpoint->id,
    payload: ['event' => 'user.created', 'user_id' => 'usr_123'],
    idempotencyKey: 'user-123-created',
);

$message = $client->getMessage($result->messageId);
$logs = $client->getLogs(limit: 100);
$metrics = $client->getMetrics();
$timeseries = $client->getTimeseriesMetrics(endpointId: $endpoint->id);

$client->replay($message->id);
$client->replayAllMessages('failed_permanent', $endpoint->id, 50);
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
