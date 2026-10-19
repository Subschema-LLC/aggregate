<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportingApi;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\ReportingApi\ApiTokenStore;
use App\Service\ReportingApi\ReportingApiSettings;
use App\Service\ViewSync\ViewCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ApiTokenStoreTest extends TestCase
{
    private string $projectDir;
    private MockClock $clock;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['REPORTING_API_PRIVATE_VIEWS', 'REPORTING_API_MAX_PAGE_SIZE'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-api-tokens-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->clock = new MockClock('2026-10-18 12:00:00 UTC');
        $this->configure();
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testATokenIsShownOnceStoredAsAHashAndAuthenticates(): void
    {
        $store = $this->store();
        [$token, $secret] = $store->create('  Power BI refresh ', null, [], $store->expiryFrom('1y'), 'scott');

        self::assertMatchesRegularExpression('/^agg_[a-f0-9]{8}_[A-Za-z0-9_-]{43}$/D', $secret);
        self::assertStringStartsWith('agg_'.$token->id.'_', $secret);
        self::assertSame('Power BI refresh', $token->name);
        self::assertNull($token->views, 'No list means every approved view');
        self::assertSame('2027-10-18T12:00:00+00:00', $token->expiresAt?->format(\DATE_ATOM));

        $file = (string) file_get_contents($store->path());
        self::assertStringNotContainsString($secret, $file, 'Only the hash is stored');
        self::assertStringNotContainsString(substr($secret, strlen('agg_'.$token->id.'_')), $file);
        self::assertSame('sha256:'.hash('sha256', $secret), Yaml::parse($file)['tokens'][$token->id]['hash']);
        self::assertSame(0600, fileperms($store->path()) & 0777, 'The token file is readable only by the application');

        self::assertSame($token->id, $this->store()->authenticate($secret)?->id);
        self::assertNull($this->store()->authenticate($secret.'x'));
        self::assertNull($this->store()->authenticate(substr($secret, 0, -1).(str_ends_with($secret, 'A') ? 'B' : 'A')), 'One changed character fails');
        self::assertNull($this->store()->authenticate('agg_'.$token->id));
        self::assertNull($this->store()->authenticate(''));
    }

    public function testExpiredAndRevokedTokensStopWorking(): void
    {
        $store = $this->store();
        [$token, $secret] = $store->create('Short', null, [], $store->expiryFrom('30d'), 'scott');
        $this->clock->modify('+29 days');
        self::assertNotNull($this->store()->authenticate($secret));
        $this->clock->modify('+2 days');
        self::assertNull($this->store()->authenticate($secret), 'Expired');
        self::assertTrue($this->store()->find($token->id)?->isExpired($this->clock->now()));

        [$other, $otherSecret] = $store->create('Other', null, [], null, 'scott');
        $this->store()->revoke($other->id);
        self::assertNull($this->store()->authenticate($otherSecret), 'Revoked');
        self::assertNull($this->store()->find($other->id));
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->revoke($other->id);
    }

    public function testExpiryHasNoUpperLimitAndNeverIsAllowed(): void
    {
        $store = $this->store();
        self::assertNull($store->expiryFrom('never'));
        self::assertSame('2126-10-18', $store->expiryFrom('100y')?->format('Y-m-d'));
        self::assertSame('2999-01-01', $store->expiryFrom('2999-01-01')?->format('Y-m-d'));
        self::assertSame('2026-11-01', $store->expiryFrom('2w')?->format('Y-m-d'));
        [$token] = $store->create('Forever', null, [], null, 'scott');
        self::assertNull($this->store()->find($token->id)?->expiresAt);
    }

    #[DataProvider('invalidExpiries')]
    public function testInvalidExpiriesAreRefused(string $choice): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->expiryFrom($choice);
    }

    public static function invalidExpiries(): iterable
    {
        yield 'past date' => ['2020-01-01'];
        yield 'today' => ['2026-10-18'];
        yield 'not a date' => ['2026-02-30'];
        yield 'zero' => ['0d'];
        yield 'unit' => ['5h'];
        yield 'beyond 9999' => ['9000y'];
        yield 'text' => ['soon'];
    }

    public function testScopeKeepsApprovedAndPrivateViewsApart(): void
    {
        $store = $this->store();
        [$token] = $store->create('Daily', ['bi_dim_device_class_v1', 'bi_anonymous_daily_v1', 'bi_anonymous_daily_v1'], [], null, 'scott');
        self::assertSame(['bi_anonymous_daily_v1', 'bi_dim_device_class_v1'], $token->views, 'Catalog order, without duplicates');
        self::assertTrue($token->mayRead('bi_anonymous_daily_v1', true));
        self::assertFalse($token->mayRead('bi_anonymous_events_v1', true));
        self::assertFalse($token->mayRead('events', true), 'Raw tables are never readable');

        foreach ([[['analytics_custom_events_v1'], []], [[], ['bi_anonymous_daily_v1']], [['events'], []], [[], ['events']], [[], []]] as [$views, $private]) {
            try {
                $store->create('Bad '.bin2hex(random_bytes(2)), $views, $private, null, 'scott');
                self::fail('A private view in views, an approved view as private, an unknown view or an empty scope must be refused.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testPrivateViewsNeedTheDeploymentToggle(): void
    {
        try {
            $this->store()->create('Raw', ['bi_dim_website_token_v1'], ['analytics_custom_events_v1'], null, 'scott');
            self::fail('Private views are off by default.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('reporting_api_private_views', $error->getMessage());
        }

        $this->configure(['reporting_api_private_views' => true]);
        [$token] = $this->store()->create('Raw', ['bi_anonymous_daily_v1'], ['analytics_custom_events_v1'], null, 'scott');
        self::assertTrue($token->mayRead('analytics_custom_events_v1', true));
        // Labels and definitions are readable with any scope.
        $metadata = array_values(array_filter(array_keys(ViewCatalog::APPROVED), [ViewCatalog::class, 'isMetadata']));
        self::assertSame(['bi_anonymous_daily_v1', ...$metadata, 'analytics_custom_events_v1'], $token->readableViews(true));

        // Turning the toggle off keeps the grant in the file but stops serving it.
        $this->configure(['reporting_api_private_views' => false]);
        $stored = $this->store()->find($token->id);
        self::assertSame(['analytics_custom_events_v1'], $stored?->privateViews);
        self::assertFalse($stored->mayRead('analytics_custom_events_v1', false));
        self::assertSame(['bi_anonymous_daily_v1', ...$metadata], $stored->readableViews(false));
    }

    public function testCreatingNeedsTheFeatureFlagButRevokingDoesNot(): void
    {
        [$token] = $this->store()->create('Before', null, [], null, 'scott');
        $this->configure(flag: false);
        try {
            $this->store()->create('After', null, [], null, 'scott');
            self::fail('Creating a token needs the reporting_api flag.');
        } catch (\RuntimeException $error) {
            self::assertNotInstanceOf(\InvalidArgumentException::class, $error);
        }
        $this->store()->revoke($token->id);
        self::assertSame([], $this->store()->tokens());
    }

    public function testNamesAreUniqueAndValidated(): void
    {
        $this->store()->create('Notebook', null, [], null, 'scott');
        foreach (['notebook', '', str_repeat('x', 81), "Tab\tname"] as $name) {
            try {
                $this->store()->create($name, null, [], null, 'scott');
                self::fail(sprintf('The name "%s" must be refused.', $name));
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount(1, $this->store()->tokens());
    }

    public function testAnInvalidFileAuthenticatesNothingAndIsNotOverwritten(): void
    {
        [, $secret] = $this->store()->create('Valid', null, [], null, 'scott');
        $path = $this->store()->path();
        $document = Yaml::parse((string) file_get_contents($path));
        $id = array_key_first($document['tokens']);
        $document['tokens'][$id]['views'] = ['events'];
        file_put_contents($path, Yaml::dump($document, 4));
        $broken = (string) file_get_contents($path);

        try {
            $this->store()->authenticate($secret);
            self::fail('An invalid file must fail closed.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('config/secrets/api-tokens.yaml is invalid', $error->getMessage());
            self::assertStringNotContainsString('sha256:', $error->getMessage());
        }
        try {
            $this->store()->create('Another', null, [], null, 'scott');
            self::fail('Nothing is saved over an invalid file.');
        } catch (\RuntimeException) {
        }
        self::assertSame($broken, file_get_contents($path));
    }

    public function testAHandEditedFileIsRead(): void
    {
        mkdir($this->projectDir.'/config/secrets', 0700, true);
        $secret = 'agg_0a1b2c3d_'.str_repeat('A', 43);
        file_put_contents($this->projectDir.'/config/secrets/api-tokens.yaml', <<<YAML
tokens:
  0a1b2c3d:
    name: Provisioned
    hash: 'sha256:{$this->hash($secret)}'
    views: [bi_anonymous_daily_v1]
    created_at: 2026-10-01T00:00:00+00:00
    created_by: ops
    expires_at: 2027-01-01
YAML);
        $token = $this->store()->authenticate($secret);
        self::assertSame('Provisioned', $token?->name);
        self::assertSame([], $token->privateViews);
        self::assertSame('2027-01-01', $token->expiresAt?->format('Y-m-d'));
    }

    public function testEmptyAndMissingFilesHaveNoTokens(): void
    {
        self::assertSame([], $this->store()->tokens());
        mkdir($this->projectDir.'/config/secrets', 0700, true);
        file_put_contents($this->projectDir.'/config/secrets/api-tokens.yaml', "# nothing yet\n");
        self::assertSame([], $this->store()->tokens());
        [$token] = $this->store()->create('One', null, [], null, 'scott');
        $this->store()->revoke($token->id);
        self::assertSame([], Yaml::parse((string) file_get_contents($this->store()->path()))['tokens']);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function store(): ApiTokenStore
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');

        return new ApiTokenStore($this->projectDir, new FeatureFlags($config), new ReportingApiSettings($config), $this->clock);
    }

    /** @param array<string, mixed> $settings */
    private function configure(array $settings = [], bool $flag = true): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'feature_flags' => ['reporting_api' => ['enabled' => $flag, 'hide_from_navigation' => false]],
            ...$settings,
        ], 4));
    }
}
