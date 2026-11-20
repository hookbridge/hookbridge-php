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

final class VersionTest extends TestCase
{
    public function testVersionConstant(): void
    {
        self::assertSame('2.2.0', HookBridge::VERSION);
    }

    public function testManagementClientSendsVersionedUserAgent(): void
    {
        self::assertSame('hookbridge-php/' . HookBridge::VERSION, $this->sentUserAgent('client'));
    }

    public function testSendClientSendsVersionedUserAgent(): void
    {
        self::assertSame('hookbridge-php/' . HookBridge::VERSION, $this->sentUserAgent('sendClient'));
    }

    private function sentUserAgent(string $property): string
    {
        $hookbridge = new HookBridge('hb_test_dummy', retries: 0);
        $reflection = new ReflectionClass($hookbridge);

        $original = $reflection->getProperty($property)->getValue($hookbridge);
        $config = $original->getConfig();

        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
        $stack->push(Middleware::history($history));
        $client = new Client([
            'base_uri' => $config['base_uri'],
            'headers' => $config['headers'],
            'handler' => $stack,
        ]);

        $client->get('/ping');

        return $history[0]['request']->getHeaderLine('User-Agent');
    }
}
