<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\PrivacyPolicy;
use App\Service\PrivacySanitizer;
use PHPUnit\Framework\TestCase;

final class PrivacyPolicyTest extends TestCase
{
    public function testOnlyAnExplicitGrantedStateEnablesEnhancedAnalytics(): void
    {
        $policy = new PrivacyPolicy($this->createStub(AggregateConfigLoader::class));

        self::assertTrue($policy->hasEnhancedConsent('granted'));
        self::assertTrue($policy->hasEnhancedConsent(' GRANTED '));
        self::assertFalse($policy->hasEnhancedConsent('denied'));
        self::assertFalse($policy->hasEnhancedConsent('unknown'));
        self::assertFalse($policy->hasEnhancedConsent(null));
        self::assertFalse($policy->hasEnhancedConsent(['granted']));
    }

    public function testExcludedPathGlobsSupportSingleAndRecursiveSegments(): void
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')
            ->willReturn(['/account/*', '/checkout/**', '/health']);
        $policy = new PrivacyPolicy($config);

        self::assertTrue($policy->isExcludedPath('/account/profile'));
        self::assertFalse($policy->isExcludedPath('/account/profile/security'));
        self::assertTrue($policy->isExcludedPath('/checkout'));
        self::assertTrue($policy->isExcludedPath('/checkout/payment/card'));
        self::assertTrue($policy->isExcludedPath('/health'));
        self::assertFalse($policy->isExcludedPath('/pricing'));
    }

    public function testEncodedSeparatorsCannotBypassSensitivePathExclusions(): void
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')->willReturn(['/patients/**']);
        $policy = new PrivacyPolicy($config);
        $sanitizer = new PrivacySanitizer();

        foreach (['/patients%2F123', '/patients%252F123', '/public/%2e%2e/patients/123'] as $path) {
            $canonical = $sanitizer->sanitizePagePath($path);
            self::assertNotNull($canonical);
            self::assertTrue($policy->isExcludedPath($canonical), $path.' bypassed the exclusion');
        }
    }
}
