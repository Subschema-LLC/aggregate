<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ConsentCategories;
use PHPUnit\Framework\TestCase;

final class ConsentCategoriesTest extends TestCase
{
    public function testAnalyticsComesFirstThenConfiguredOtherTagAndTagManagerCategoriesOnceEach(): void
    {
        self::assertSame(['analytics'], ConsentCategories::builtin(['analytics'], ['enabled' => true, 'name' => 'Shop']));
        self::assertSame(['analytics', 'preferences', 'functional', 'social', 'marketing'], ConsentCategories::builtin(
            ['analytics', 'functional', 'marketing'],
            [
                'categories' => ['preferences', 'analytics', 'functional'],
                'external_tags' => [
                    ['id' => 'chat', 'name' => 'Chat', 'category' => 'functional'],
                    ['id' => 'share', 'name' => 'Share buttons', 'category' => 'social'],
                ],
            ],
        ));
        self::assertSame(['analytics'], ConsentCategories::builtin(['none'], []), 'none is never a category to ask about');
    }
}
