<?php

declare(strict_types=1);

namespace HookBridge\Tests;

use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use HookBridge\HookBridge;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class VersionTest extends TestCase
{
    public function testVersionConstant(): void
    {
        self::assertSame('2.3.0', HookBridge::VERSION);
    }

    public function testManagementClientSendsVersionedUserAgent(): void
    {
        $hookbridge = new HookBridge('hb_test_dummy', retries: 0);
        $history = $this->mockHandler($hookbridge, 'client', [
            new Response(200, [], json_encode(['data' => [], 'meta' => ['has_more' => false]], JSON_THROW_ON_ERROR)),
        ]);

        $hookbridge->listMessageActions('msg_1');

        $this->assertUserAgent($history);
    }

    public function testSendClientSendsVersionedUserAgent(): void
    {
        $hookbridge = new HookBridge('hb_test_dummy', retries: 0);
        $history = $this->mockHandler($hookbridge, 'sendClient', [
            new Response(200, [], json_encode(['data' => ['message_id' => 'msg_1', 'status' => 'queued']], JSON_THROW_ON_ERROR)),
        ]);

        $hookbridge->send('ep_1', ['event' => 'test']);

        $this->assertUserAgent($history);
    }

    private function mockHandler(HookBridge $hookbridge, string $property, array $responses): ArrayObject
    {
        $history = new ArrayObject();
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $client = (new ReflectionClass($hookbridge))->getProperty($property)->getValue($hookbridge);
        $config = new ReflectionProperty(Client::class, 'config');
        $config->setValue($client, ['handler' => $stack] + $config->getValue($client));

        return $history;
    }

    private function assertUserAgent(ArrayObject $history): void
    {
        self::assertCount(1, $history);
        $userAgent = $history[0]['request']->getHeaderLine('User-Agent');
        self::assertSame('hookbridge-php/' . HookBridge::VERSION, $userAgent);
        self::assertSame('hookbridge-php/2.3.0', $userAgent);
    }
}
