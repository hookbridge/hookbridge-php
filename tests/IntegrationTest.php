<?php

declare(strict_types=1);

namespace HookBridge\Tests;

use HookBridge\Exception\AuthenticationException;
use HookBridge\Exception\NotFoundException;
use HookBridge\Exception\ValidationException;
use HookBridge\HookBridge;
use HookBridge\Response\CreateEndpointResponse;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for HookBridge PHP SDK.
 *
 * These tests run against a live API server.
 *
 * Required environment variables:
 *   HOOKBRIDGE_API_KEY - A valid API key for authentication
 *   HOOKBRIDGE_BASE_URL - API base URL (e.g., https://api.hookbridge.io)
 *   HOOKBRIDGE_SEND_URL - Send URL (e.g., https://send.hookbridge.io)
 *   HOOKBRIDGE_TEST_ENDPOINT_URL - Public test receiver URL for webhook tests
 *
 * Example:
 *   HOOKBRIDGE_BASE_URL=https://api.hookbridge.io \
 *   HOOKBRIDGE_SEND_URL=https://send.hookbridge.io \
 *   HOOKBRIDGE_TEST_ENDPOINT_URL=https://example.com/webhooks/test \
 *   HOOKBRIDGE_API_KEY=hb_test_xxx \
 *   composer test
 */
class IntegrationTest extends TestCase
{
    private static ?HookBridge $client = null;
    private static string $testEndpointUrl;
    private static ?CreateEndpointResponse $testEndpoint = null;

    public static function setUpBeforeClass(): void
    {
        $apiKey = getenv('HOOKBRIDGE_API_KEY');
        $baseUrl = getenv('HOOKBRIDGE_BASE_URL');
        $sendUrl = getenv('HOOKBRIDGE_SEND_URL');

        if (empty($apiKey) || empty($baseUrl)) {
            self::markTestSkipped('HOOKBRIDGE_API_KEY and HOOKBRIDGE_BASE_URL must be set');
        }

        self::$testEndpointUrl = getenv('HOOKBRIDGE_TEST_ENDPOINT_URL')
            ?: 'https://example.com/webhooks/test';

        self::$client = new HookBridge($apiKey, $baseUrl, $sendUrl ?: null);

        // Create a test endpoint for webhook tests
        self::$testEndpoint = self::$client->createEndpoint(
            url: self::$testEndpointUrl,
            description: 'Integration test endpoint (PHP)'
        );
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up: delete the test endpoint
        if (self::$client !== null && self::$testEndpoint !== null) {
            try {
                self::$client->deleteEndpoint(self::$testEndpoint->id);
            } catch (\Exception) {
                // Ignore cleanup errors
            }
        }
    }

    public function testClientRequiresApiKey(): void
    {
        $this->expectException(ValidationException::class);
        new HookBridge('');
    }

    public function testSendWebhook(): string
    {
        $result = self::$client->send(
            endpointId: self::$testEndpoint->id,
            payload: [
                'event' => 'test.integration.php',
                'timestamp' => date('c'),
                'data' => ['test' => true],
            ]
        );

        $this->assertNotEmpty($result->messageId);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $result->messageId);
        $this->assertEquals('queued', $result->status);

        return $result->messageId;
    }

    public function testSendWebhookWithCustomHeaders(): void
    {
        $result = self::$client->send(
            endpointId: self::$testEndpoint->id,
            payload: ['event' => 'test.headers'],
            headers: [
                'X-Custom-Header' => 'test-value',
                'X-Request-Id' => 'integration-test-php-123',
            ]
        );

        $this->assertNotEmpty($result->messageId);
        $this->assertEquals('queued', $result->status);
    }

    public function testSendWebhookWithIdempotencyKey(): void
    {
        $idempotencyKey = 'test-php-' . time() . '-' . bin2hex(random_bytes(8));

        $result1 = self::$client->send(
            endpointId: self::$testEndpoint->id,
            payload: ['event' => 'test.idempotent', 'key' => $idempotencyKey],
            idempotencyKey: $idempotencyKey
        );

        // Same request with same idempotency key should return same message ID
        $result2 = self::$client->send(
            endpointId: self::$testEndpoint->id,
            payload: ['event' => 'test.idempotent', 'key' => $idempotencyKey],
            idempotencyKey: $idempotencyKey
        );

        $this->assertEquals($result1->messageId, $result2->messageId);
    }

    public function testSendWebhookRejectsInvalidEndpointId(): void
    {
        $this->expectException(NotFoundException::class);

        self::$client->send(
            endpointId: 'ep_nonexistent_12345',
            payload: ['event' => 'test.invalid']
        );
    }

    #[Depends('testSendWebhook')]
    public function testGetMessage(string $messageId): void
    {
        // Give it a moment to be processed
        sleep(1);

        $message = self::$client->getMessage($messageId);

        $this->assertEquals($messageId, $message->id);
        $this->assertNotEmpty($message->projectId);
        $this->assertContains($message->status, ['queued', 'delivering', 'succeeded', 'pending_retry', 'failed_permanent']);
        $this->assertGreaterThanOrEqual(0, $message->attemptCount);
    }

    public function testGetMessageNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        self::$client->getMessage('01935abc-def0-7123-4567-890abcdef012');
    }

    public function testGetLogs(): void
    {
        $logs = self::$client->getLogs();

        $this->assertIsArray($logs->messages);
        $this->assertIsBool($logs->hasMore);

        if (count($logs->messages) > 0) {
            $msg = $logs->messages[0];
            $this->assertNotEmpty($msg->messageId);
            $this->assertNotEmpty($msg->status);
        }
    }

    public function testGetLogsWithStatusFilter(): void
    {
        $logs = self::$client->getLogs(status: 'succeeded');

        foreach ($logs->messages as $msg) {
            $this->assertEquals('succeeded', $msg->status);
        }
    }

    public function testGetLogsWithLimit(): void
    {
        $logs = self::$client->getLogs(limit: 5);

        $this->assertLessThanOrEqual(5, count($logs->messages));
    }

    public function testGetLogsPagination(): void
    {
        $firstPage = self::$client->getLogs(limit: 5);

        if ($firstPage->hasMore && $firstPage->nextCursor !== null) {
            $secondPage = self::$client->getLogs(limit: 5, cursor: $firstPage->nextCursor);

            $this->assertIsArray($secondPage->messages);

            // Messages should be different
            if (count($firstPage->messages) > 0 && count($secondPage->messages) > 0) {
                $this->assertNotEquals(
                    $firstPage->messages[0]->messageId,
                    $secondPage->messages[0]->messageId
                );
            }
        }
    }

    public function testGetMetricsDefaultWindow(): void
    {
        $metrics = self::$client->getMetrics();

        $this->assertEquals('24h', $metrics->window);
        $this->assertIsInt($metrics->totalMessages);
        $this->assertIsInt($metrics->succeeded);
        $this->assertIsInt($metrics->failed);
        $this->assertIsInt($metrics->retries);
        $this->assertIsFloat($metrics->successRate);
        $this->assertIsInt($metrics->avgLatencyMs);
    }

    public function testGetMetricsDifferentWindows(): void
    {
        $windows = ['1h', '24h', '7d', '30d'];

        foreach ($windows as $window) {
            $metrics = self::$client->getMetrics($window);
            $this->assertEquals($window, $metrics->window);
        }
    }

    public function testGetDLQMessages(): void
    {
        $dlq = self::$client->getDLQMessages();

        $this->assertIsArray($dlq->messages);
        $this->assertIsBool($dlq->hasMore);
    }

    public function testGetDLQMessagesWithLimit(): void
    {
        $dlq = self::$client->getDLQMessages(limit: 10);

        $this->assertLessThanOrEqual(10, count($dlq->messages));
    }

    public function testListAPIKeys(): void
    {
        $keys = self::$client->listAPIKeys();

        $this->assertIsArray($keys);
        $this->assertGreaterThan(0, count($keys));

        $key = $keys[0];
        $this->assertNotEmpty($key->keyId);
        $this->assertNotEmpty($key->prefix);
    }

    public function testCreateAndDeleteAPIKey(): void
    {
        $newKey = self::$client->createAPIKey(
            mode: 'test',
            label: 'Integration Test Key (PHP)'
        );

        $this->assertNotEmpty($newKey->keyId);
        $this->assertNotEmpty($newKey->key);
        $this->assertStringContainsString('_test_', $newKey->key);
        $this->assertEquals('Integration Test Key (PHP)', $newKey->label);

        // Delete the key we just created
        self::$client->deleteAPIKey($newKey->keyId);

        // Verify it's deleted by checking the list
        $keysAfterDelete = self::$client->listAPIKeys();
        $stillExists = false;
        foreach ($keysAfterDelete as $k) {
            if ($k->keyId === $newKey->keyId) {
                $stillExists = true;
                break;
            }
        }
        $this->assertFalse($stillExists);
    }

    public function testDeleteAPIKeyNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        self::$client->deleteAPIKey('key_nonexistent_12345');
    }

    public function testAuthenticationError(): void
    {
        $badClient = new HookBridge(
            'hb_test_invalid_key_12345',
            getenv('HOOKBRIDGE_BASE_URL') ?: null
        );

        $this->expectException(AuthenticationException::class);

        $badClient->getLogs();
    }

    public function testCreateGetUpdateDeleteEndpoint(): void
    {
        // Create
        $endpoint = self::$client->createEndpoint(
            url: 'https://example.com/webhooks/test-endpoint',
            description: 'Test endpoint for CRUD operations'
        );

        $this->assertNotEmpty($endpoint->id);
        $this->assertEquals('https://example.com/webhooks/test-endpoint', $endpoint->url);
        $this->assertNotEmpty($endpoint->signingSecret);
        $this->assertStringStartsWith('whsec_', $endpoint->signingSecret);

        // Get
        $retrieved = self::$client->getEndpoint($endpoint->id);
        $this->assertEquals($endpoint->id, $retrieved->id);
        $this->assertEquals($endpoint->url, $retrieved->url);

        // Update
        $updated = self::$client->updateEndpoint(
            endpointId: $endpoint->id,
            description: 'Updated description'
        );
        $this->assertEquals('Updated description', $updated->description);

        // Delete
        self::$client->deleteEndpoint($endpoint->id);

        // Verify deleted
        $this->expectException(NotFoundException::class);
        self::$client->getEndpoint($endpoint->id);
    }

    public function testListEndpoints(): void
    {
        $list = self::$client->listEndpoints();

        $this->assertIsArray($list->endpoints);
        $this->assertIsBool($list->hasMore);

        // Our test endpoint should be in the list
        $found = false;
        foreach ($list->endpoints as $ep) {
            if ($ep->id === self::$testEndpoint->id) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Test endpoint should be in the list');
    }

    public function testRotateEndpointSecret(): void
    {
        // Create a dedicated endpoint for this test
        $endpoint = self::$client->createEndpoint(
            url: 'https://example.com/webhooks/rotate-test',
            description: 'Rotate test endpoint'
        );

        try {
            $originalSecret = $endpoint->signingSecret;

            $rotated = self::$client->rotateEndpointSecret($endpoint->id);

            $this->assertEquals($endpoint->id, $rotated->id);
            $this->assertNotEmpty($rotated->signingSecret);
            $this->assertNotEquals($originalSecret, $rotated->signingSecret);
        } finally {
            self::$client->deleteEndpoint($endpoint->id);
        }
    }
}
