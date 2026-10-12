<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\BotTrafficSettings;
use App\Service\CollectionControlsForm;
use App\Service\CustomDataSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\Yaml\Yaml;

final class CollectionControlsFormTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-collection-form-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([]));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testAValidSubmissionBecomesYamlSettingsAndRetrySettings(): void
    {
        $form = CollectionControlsForm::fromSubmission(new ParameterBag([
            'collection_profile' => 'strict',
            'anonymous_tracking_enabled' => '1',
            'anonymous_excluded_paths' => " /account/**\n\n/checkout/*\n/account/** ",
            'anonymous_geo_enabled' => '1',
            'anonymous_geo_level' => 'country',
            'anonymous_geo_database_path' => ' /var/lib/GeoLite2-Country.mmdb ',
            'tracking_retry_enabled' => '0',
            'tracking_retry_batch_size' => '25',
            'google_consent_mode' => 'updates',
        ]), $this->current());

        self::assertSame([
            'collection_profile' => 'strict',
            'anonymous_tracking_enabled' => true,
            'anonymous_excluded_paths' => ['/account/**', '/checkout/*'],
            'anonymous_geo_enabled' => true,
            'anonymous_geo_level' => 'country',
            'anonymous_geo_database_path' => '/var/lib/GeoLite2-Country.mmdb',
            'google_consent_mode' => 'updates',
        ], $form['settings']);
        self::assertSame(['tracking_retry_enabled' => false, 'tracking_retry_batch_size' => '25'], $form['retry']);
        self::assertSame('strict', $form['collection_profile']);
    }

    public function testOmittedOrUnchangedOptionalFieldsAreNotWritten(): void
    {
        $form = CollectionControlsForm::fromSubmission(new ParameterBag(['google_consent_mode' => 'all']), $this->current());
        self::assertArrayNotHasKey('google_consent_mode', $form['settings'], 'the default is not pinned');
        self::assertSame([], $form['retry']);
        self::assertSame('standard', $form['settings']['collection_profile']);
        self::assertFalse($form['settings']['anonymous_tracking_enabled']);
        self::assertSame([], $form['settings']['anonymous_excluded_paths']);
        self::assertSame('macro_region', $form['settings']['anonymous_geo_level']);

        // A saved invalid value is replaced even by the default.
        $form = CollectionControlsForm::fromSubmission(new ParameterBag(['google_consent_mode' => 'all']), $this->current(['google_consent_mode' => 'sometimes']));
        self::assertSame('all', $form['settings']['google_consent_mode']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidSubmissions(): iterable
    {
        yield 'profile' => [['collection_profile' => 'relaxed'], 'Collection profile must be standard or strict.'];
        yield 'google signals' => [['google_consent_mode' => 'sometimes'], 'Google Consent Mode signals must be one of all, updates, off.'];
        yield 'geography level' => [['anonymous_geo_level' => 'city'], 'Geography level must be macro-region or country.'];
        yield 'control character in path' => [['anonymous_geo_database_path' => "/geo\x01.mmdb"], 'The GeoIP database path is invalid or too long.'];
        foreach (['https://example.test/geo.mmdb', 'phar://geo.mmdb', '\\\\server\\geo.mmdb', '//server/geo.mmdb', '/var/lib/geo.dat', '../geo.mmdb'] as $path) {
            yield 'geography path '.$path => [['anonymous_geo_database_path' => $path], 'Use a local filesystem path ending in .mmdb; URI, stream-wrapper, UNC/network, and project-root escape paths are not allowed.'];
        }
        yield 'too long' => [['anonymous_excluded_paths' => str_repeat('/a', 12_801)], 'Excluded paths are too long.'];
        yield 'too many' => [['anonymous_excluded_paths' => implode("\n", array_map(static fn (int $i): string => '/p'.$i, range(1, 101)))], 'Use no more than 100 anonymous tracking exclusions.'];
        yield 'query string' => [['anonymous_excluded_paths' => "/ok\n/search?q=1"], 'Invalid excluded path "/search?q=1". Use a path beginning with /, omit query strings/fragments, and keep it at most 512 characters.'];
        yield 'relative' => [['anonymous_excluded_paths' => 'account'], 'Invalid excluded path "account". Use a path beginning with /, omit query strings/fragments, and keep it at most 512 characters.'];
    }

    /** @param array<string, string> $values */
    #[DataProvider('invalidSubmissions')]
    public function testInvalidSubmissionsAreRejectedWithThePageMessage(array $values, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        CollectionControlsForm::fromSubmission(new ParameterBag($values), $this->current());
    }

    public function testBotSettingsAreWrittenOnlyWhenTheyChange(): void
    {
        $form = CollectionControlsForm::fromSubmission(
            new ParameterBag(['bot_traffic' => 'drop', 'bot_user_agents' => " ExampleMonitor \n\nload-test\nexamplemonitor"]),
            $this->current(),
            $this->bots(),
        );
        self::assertSame('drop', $form['settings']['bot_traffic']);
        self::assertSame(['ExampleMonitor', 'load-test'], $form['settings']['bot_user_agents']);

        $form = CollectionControlsForm::fromSubmission(new ParameterBag(['bot_traffic' => 'label', 'bot_user_agents' => '']), $this->current(), $this->bots());
        self::assertArrayNotHasKey('bot_traffic', $form['settings'], 'the default is not pinned');
        self::assertArrayNotHasKey('bot_user_agents', $form['settings']);

        $form = CollectionControlsForm::fromSubmission(new ParameterBag(['bot_traffic' => 'drop', 'bot_user_agents' => 'ExampleMonitor']), $this->current(), $this->bots(['bot_traffic' => 'drop', 'bot_user_agents' => ['ExampleMonitor']]));
        self::assertArrayNotHasKey('bot_traffic', $form['settings']);
        self::assertArrayNotHasKey('bot_user_agents', $form['settings']);

        $form = CollectionControlsForm::fromSubmission(new ParameterBag(['bot_user_agents' => '']), $this->current(), $this->bots(['bot_user_agents' => ['ExampleMonitor']]));
        self::assertSame([], $form['settings']['bot_user_agents'], 'clearing the phrases is saved');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidBotSubmissions(): iterable
    {
        yield 'mode' => [['bot_traffic' => 'block'], 'Bot traffic must be label or drop.'];
        $phrases = 'Enter at most 50 bot User-Agent phrases, one per line, each 3 to 100 characters without control characters.';
        yield 'short phrase' => [['bot_user_agents' => 'ab'], $phrases];
        yield 'long phrase' => [['bot_user_agents' => str_repeat('x', 101)], $phrases];
        yield 'too many' => [['bot_user_agents' => implode("\n", array_map(static fn (int $i): string => 'phrase-'.$i, range(1, 51)))], $phrases];
        yield 'too long' => [['bot_user_agents' => str_repeat("phrase\n", 2400)], 'Bot User-Agent phrases are too long.'];
    }

    /** @param array<string, string> $values */
    #[DataProvider('invalidBotSubmissions')]
    public function testInvalidBotSettingsAreRejectedWithThePageMessage(array $values, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        CollectionControlsForm::fromSubmission(new ParameterBag($values), $this->current(), $this->bots());
    }

    public function testWindowsDrivePathsAreLocal(): void
    {
        CollectionControlsForm::assertLocalGeoDatabasePath('C:\\GeoIP\\GeoLite2-Country.mmdb');
        CollectionControlsForm::assertLocalGeoDatabasePath('');
        $this->addToAssertionCount(2);
    }

    private function bots(array $config = []): BotTrafficSettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($config));

        return new BotTrafficSettings(new AggregateConfigLoader($this->projectDir, 'test'));
    }

    private function current(array $config = []): CustomDataSettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($config));

        return new CustomDataSettings(new AggregateConfigLoader($this->projectDir, 'test'));
    }
}
