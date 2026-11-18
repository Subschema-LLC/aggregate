<?php

declare(strict_types=1);

namespace App\Tests\Entities\TagManager;

use App\Entities\TagManager\TagChains;
use PHPUnit\Framework\TestCase;

final class TagChainsTest extends TestCase
{
    public function testAcceptsValidChaining(): void
    {
        $tags = [
            [
                'id' => 'base',
                'type' => 'script',
                'trigger' => ['type' => 'dom_ready'],
            ],
            [
                'id' => 'dependent',
                'type' => 'call',
                'trigger' => ['type' => 'dom_ready'],
                'after' => 'base',
            ],
        ];

        self::assertSame($tags, TagChains::validate($tags));
    }

    public function testRejectsChainPointingToMissingTag(): void
    {
        $tags = [
            [
                'id' => 'orphan',
                'type' => 'call',
                'trigger' => ['type' => 'dom_ready'],
                'after' => 'nonexistent',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag orphan runs after nonexistent, but there is no tag with that ID.');

        TagChains::validate($tags);
    }

    public function testRejectsChainPointingToSelf(): void
    {
        $tags = [
            [
                'id' => 'self_loop',
                'type' => 'script',
                'trigger' => ['type' => 'dom_ready'],
                'after' => 'self_loop',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag self_loop cannot run after itself.');

        TagChains::validate($tags);
    }

    public function testRejectsCircularChain(): void
    {
        $tags = [
            [
                'id' => 'tagA',
                'type' => 'script',
                'trigger' => ['type' => 'dom_ready'],
                'after' => 'tagB',
            ],
            [
                'id' => 'tagB',
                'type' => 'script',
                'trigger' => ['type' => 'dom_ready'],
                'after' => 'tagA',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tags tagA and tagB wait for each other, so none of them would run.');

        TagChains::validate($tags);
    }

    public function testRejectsTargetThatCanRunMultipleTimes(): void
    {
        $tags = [
            [
                'id' => 'multi_run',
                'type' => 'call',
                'trigger' => ['type' => 'document_event', 'event' => 'click'],
            ],
            [
                'id' => 'dependent',
                'type' => 'call',
                'trigger' => ['type' => 'dom_ready'],
                'after' => 'multi_run',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag dependent runs after multi_run, which can run many times.');

        TagChains::validate($tags);
    }
}
