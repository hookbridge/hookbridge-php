<?php

declare(strict_types=1);

namespace HookBridge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ResponseAutoloadTest extends TestCase
{
    public static function eventResponseClasses(): array
    {
        $names = [
            'EventSubscriptions',
            'SendEventResponse',
            'EventType',
            'ListEventTypesResponse',
            'EventTypeGroup',
            'Event',
            'EventMessage',
            'ListEventsResponse',
            'EventDetail',
            'RecoverJobStarted',
            'RecoverJob',
            'SendTestEventResponse',
        ];

        return array_map(static fn (string $n): array => [$n], $names);
    }

    #[RunInSeparateProcess]
    #[DataProvider('eventResponseClasses')]
    public function testClassAutoloadsWithoutClient(string $name): void
    {
        $class = 'HookBridge\\Response\\' . $name;

        $this->assertTrue(class_exists($class));
        $this->assertFalse(class_exists('HookBridge\\HookBridge', false));
    }

    #[RunInSeparateProcess]
    public function testEventSubscriptionsConstructsBeforeClientLoads(): void
    {
        $subscriptions = new \HookBridge\Response\EventSubscriptions(['order.*'], null);

        $this->assertSame(['event_types' => ['order.*'], 'groups' => []], $subscriptions->toArray());
    }
}
