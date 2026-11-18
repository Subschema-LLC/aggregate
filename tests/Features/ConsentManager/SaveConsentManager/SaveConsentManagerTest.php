<?php

declare(strict_types=1);

namespace App\Tests\Features\ConsentManager\SaveConsentManager;

use App\Entities\ConsentManager\ConsentManagers;
use App\Features\ConsentManager\SaveConsentManager\SaveConsentManager;
use PHPUnit\Framework\TestCase;

final class SaveConsentManagerTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->saved = [];
    }

    private function store(): ConsentManagers
    {
        return new class($this->saved) implements ConsentManagers {
            /** @param array<string, array<string, mixed>> $saved */
            public function __construct(private array &$saved)
            {
            }

            public function consent(string $siteId): array
            {
                return $this->saved[$siteId] ?? [];
            }

            public function saveConsent(string $siteId, array $consent): void
            {
                $this->saved[$siteId] = $consent;
            }

            public function previewConsent(string $siteId, array $changes): array
            {
                return array_replace_recursive($this->consent($siteId), $changes);
            }
        };
    }

    public function testDelegatesSavingToStore(): void
    {
        $feature = new SaveConsentManager($this->store());
        $consent = ['enabled' => true, 'name' => 'Acme Privacy'];

        $feature->save('site-42', $consent);

        self::assertSame($consent, $this->saved['site-42']);
    }
}
