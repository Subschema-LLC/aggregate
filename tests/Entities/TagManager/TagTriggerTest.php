<?php

declare(strict_types=1);

namespace App\Tests\Entities\TagManager;

use App\Entities\TagManager\TagTrigger;
use PHPUnit\Framework\TestCase;

final class TagTriggerTest extends TestCase
{
    public function testAcceptsLifecycleTriggersWithoutEvent(): void
    {
        self::assertSame(['type' => 'dom_ready'], TagTrigger::validate(['type' => 'dom_ready']));
        self::assertSame(['type' => 'window_load'], TagTrigger::validate(['type' => 'window_load']));
    }

    public function testRejectsEventOnLifecycleTriggers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Document-ready and window-load triggers do not use an event name.');

        TagTrigger::validate(['type' => 'dom_ready', 'event' => 'custom']);
    }

    public function testAcceptsEventTriggersWithValidName(): void
    {
        self::assertSame(
            ['type' => 'document_event', 'event' => 'click_btn'],
            TagTrigger::validate(['type' => 'document_event', 'event' => 'click_btn']),
        );
        self::assertSame(
            ['type' => 'data_layer', 'event' => 'analytics.purchase'],
            TagTrigger::validate(['type' => 'data_layer', 'event' => 'analytics.purchase']),
        );
    }

    public function testRejectsInvalidTriggerType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A tag trigger type must be dom_ready, window_load');

        TagTrigger::validate(['type' => 'unknown_trigger']);
    }

    public function testRejectsInvalidEventName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An event trigger needs a name of 1–100 letters');

        TagTrigger::validate(['type' => 'window_event', 'event' => '123_invalid_start']);
    }
}
