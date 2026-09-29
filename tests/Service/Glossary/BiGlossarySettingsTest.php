<?php

declare(strict_types=1);

namespace App\Tests\Service\Glossary;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\Glossary\BiGlossarySettings;
use App\Service\Glossary\BuiltinGlossaryCatalog;
use App\Service\Glossary\GlossaryValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

final class BiGlossarySettingsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-glossary-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->environment = [$_ENV, $_SERVER];
        foreach (['BI_GLOSSARY', 'ANONYMOUS_TRACKING_ENABLED', 'ANONYMOUS_EXCLUDED_PATHS'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach (glob($this->projectDir.'/config/*') as $file) {
            unlink($file);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testDefaultsIgnoreUppercaseEnvironmentOverrides(): void
    {
        $_ENV['BI_GLOSSARY'] = 'broken';
        $_SERVER['BI_GLOSSARY'] = 'also-broken';
        self::assertSame(['default_locale' => 'en', 'locales' => ['en'], 'values' => [], 'columns' => []], $this->settings()->get());
    }

    public function testNormalizesLocaleCaseAndDefaultLocaleStringsAndTrimsText(): void
    {
        $settings = $this->settings();
        $result = $settings->validate([
            'default_locale' => 'FR-ca', 'locales' => ['FR-ca', 'EN', 'ZH-hANT', 'es-419'],
            'values' => ['device_class' => ['tablet' => [
                'label' => ' Tablette ', 'group' => ['en' => ' Portable '],
                'description' => ['fr-CA' => ' Description. '], 'sort' => 0,
            ]]],
        ]);
        self::assertSame('fr-CA', $result['default_locale']);
        self::assertSame(['fr-CA', 'en', 'zh-Hant', 'es-419'], $result['locales']);
        self::assertSame(['label' => ['fr-CA' => 'Tablette'], 'group' => ['en' => 'Portable'], 'description' => ['fr-CA' => 'Description.'], 'sort' => 0], $result['values']['device_class']['tablet']);
    }

    public function testDimensionsUseTextAliasesAndColumnsIncludeNumericAliases(): void
    {
        $settings = $this->settings([
            'custom_data_properties' => ['revenue' => ['type' => 'double', 'column' => 'revenue_text', 'numeric_column' => 'revenue_amount']],
        ]);
        self::assertArrayHasKey('revenue_text', $settings->dimensions());
        self::assertArrayNotHasKey('revenue_amount', $settings->dimensions());
        self::assertContains('revenue_text', $settings->coveredColumns()['analytics_custom_events_v1']);
        self::assertContains('revenue_amount', $settings->coveredColumns()['analytics_custom_events_v1']);
        $normalized = $settings->validate([
            'values' => ['revenue_text' => [0 => ['label' => 'Zero'], 'Case' => [], 'case' => [], "Operator's value" => []]],
            'columns' => ['analytics_custom_events_v1' => ['revenue_amount' => ['label' => 'Revenue']]],
        ]);
        self::assertCount(4, $normalized['values']['revenue_text']);
        self::assertSame(['en' => 'Zero'], $normalized['values']['revenue_text'][0]['label']);
    }

    #[DataProvider('invalidBlocks')]
    public function testEveryValidationFailureHasAFieldPathAndNeverChangesYaml(mixed $block, string $path): void
    {
        $settings = $this->settings(['app_host' => 'https://preserve.example']);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        try {
            $settings->save($block);
            self::fail('Invalid block was accepted.');
        } catch (GlossaryValidationException $exception) {
            self::assertArrayHasKey($path, $exception->errors(), $exception->getMessage());
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        }
    }

    public static function invalidBlocks(): iterable
    {
        foreach ([null, false, 7, 'text', ['list']] as $index => $value) {
            yield 'mapping '.$index => [$value, 'bi_glossary'];
        }
        yield 'unknown option' => [['unexpected' => true], 'bi_glossary.unexpected'];
        foreach ([null, 7, '', 'e', 'es_MX', 'en-US-extra', 'en-1234', 'en--US'] as $index => $locale) {
            yield 'default locale '.$index => [['default_locale' => $locale], 'bi_glossary.default_locale'];
        }
        foreach ([null, false, [], 'en', ['en' => 'en'], array_fill(0, 21, 'en')] as $index => $locales) {
            yield 'locales '.$index => [['locales' => $locales], 'bi_glossary.locales'];
        }
        yield 'default locale must be published' => [['default_locale' => 'es', 'locales' => ['en']], 'bi_glossary.locales'];
        yield 'bad published locale' => [['locales' => ['en', 'es_MX']], 'bi_glossary.locales.1'];
        yield 'duplicate canonical locale' => [['locales' => ['en', 'EN']], 'bi_glossary.locales.1'];
        foreach (['values', 'columns'] as $section) {
            yield $section.' non-mapping' => [[$section => ['invalid']], 'bi_glossary.'.$section];
            yield $section.' null' => [[$section => null], 'bi_glossary.'.$section];
        }
        yield 'unknown dimension' => [['values' => ['page_path' => []]], 'bi_glossary.values.page_path'];
        yield 'property name is not an alias' => [['values' => ['unmodeled' => []]], 'bi_glossary.values.unmodeled'];
        yield 'unknown view' => [['columns' => ['events' => []]], 'bi_glossary.columns.events'];
        yield 'unknown column' => [['columns' => ['bi_anonymous_events_v1' => ['visitor_id' => []]]], 'bi_glossary.columns.bi_anonymous_events_v1.visitor_id'];
        foreach ([['device_class', 'tablett'], ['viewport_bucket', 'huge'], ['referrer_channel', 'paid'], ['geo_level', 'city'], ['privacy_mode', 'private'], ['geo_area', 'country:XX'], ['geo_area', 'continent:DE'], ['goal_event', 'deleted']] as [$dimension, $code]) {
            yield 'unknown '.$dimension.' '.$code => [['values' => [$dimension => [$code => []]]], 'bi_glossary.values.'.$dimension.'.'.$code];
        }
        foreach (['bad name', 'user@example.org', '1invalid', str_repeat('a', 101)] as $index => $code) {
            yield 'unsafe event '.$index => [['values' => ['event_name' => [$code => []]]], 'bi_glossary.values.event_name.'.$code];
        }
        foreach (['', ' trailing ', "control\nvalue", str_repeat('a', 192), "\xFF"] as $index => $code) {
            yield 'custom code '.$index => [['values' => ['utm_medium' => [$code => []]]], 'bi_glossary.values.utm_medium.'.$code];
        }
        yield 'non-mapping codes' => [['values' => ['device_class' => 'tablet']], 'bi_glossary.values.device_class'];
        yield 'non-mapping definition' => [['values' => ['device_class' => ['tablet' => 'Tablet']]], 'bi_glossary.values.device_class.tablet'];
        yield 'unexpected definition key' => [['values' => ['device_class' => ['tablet' => ['labels' => 'Tablet']]]], 'bi_glossary.values.device_class.tablet.labels'];
        yield 'group forbidden on columns' => [['columns' => ['bi_anonymous_events_v1' => ['event_count' => ['group' => 'Counts']]]], 'bi_glossary.columns.bi_anonymous_events_v1.event_count.group'];
        foreach (['label' => 191, 'group' => 191, 'description' => 1000] as $field => $maximum) {
            foreach ([null, true, 7, ['list']] as $index => $text) {
                yield $field.' wrong type '.$index => [['values' => ['device_class' => ['tablet' => [$field => $text]]]], 'bi_glossary.values.device_class.tablet.'.$field];
            }
            foreach (['', ' ', "text\nmore", "text\x7f", "text\u{0085}", "\xFF", str_repeat('x', $maximum + 1), str_repeat('é', intdiv($maximum, 2) + 1)] as $index => $text) {
                yield $field.' bad text '.$index => [['values' => ['device_class' => ['tablet' => [$field => $text]]]], 'bi_glossary.values.device_class.tablet.'.$field.'.en'];
            }
            yield $field.' unpublished translation' => [['values' => ['device_class' => ['tablet' => [$field => ['es' => 'Texto']]]]], 'bi_glossary.values.device_class.tablet.'.$field.'.es'];
            yield $field.' locale typo' => [['values' => ['device_class' => ['tablet' => [$field => ['es_MX' => 'Texto']]]]], 'bi_glossary.values.device_class.tablet.'.$field.'.es_MX'];
            yield $field.' duplicate normalized translation' => [['values' => ['device_class' => ['tablet' => [$field => ['en' => 'One', 'EN' => 'Two']]]]], 'bi_glossary.values.device_class.tablet.'.$field.'.EN'];
            yield $field.' nonstring translation' => [['values' => ['device_class' => ['tablet' => [$field => ['en' => false]]]]], 'bi_glossary.values.device_class.tablet.'.$field.'.en'];
        }
        foreach ([null, true, '10', 1.2, -1, 100001] as $index => $sort) {
            yield 'sort '.$index => [['values' => ['device_class' => ['tablet' => ['sort' => $sort]]]], 'bi_glossary.values.device_class.tablet.sort'];
        }
        $values = [];
        foreach (range(1, 2001) as $number) {
            $values['event_'.$number] = [];
        }
        yield 'value bound' => [['values' => ['event_name' => $values]], 'bi_glossary.values'];
        $columns = [];
        foreach (range(1, 501) as $number) {
            $columns['column_'.$number] = [];
        }
        yield 'column bound' => [['columns' => ['bi_anonymous_events_v1' => $columns]], 'bi_glossary.columns'];
    }

    public function testMaximumTextAndSortAreAccepted(): void
    {
        $normalized = $this->settings()->validate(['values' => ['device_class' => ['tablet' => [
            'label' => str_repeat('x', 191), 'group' => str_repeat('x', 191),
            'description' => str_repeat('x', 1000), 'sort' => 100000,
        ]]]]);
        self::assertSame(100000, $normalized['values']['device_class']['tablet']['sort']);
    }

    public function testSavePreservesUnrelatedSettingsAndGoalsFileByteForByte(): void
    {
        $original = ['app_host' => 'https://preserve.example', 'updates_branch' => 'uat', 'custom_data_properties' => ['plan' => ['column' => 'plan_name']], 'deployment' => ['keep' => true]];
        $settings = $this->settings($original);
        $goals = "# Operator-owned goal taxonomy\nparameters:\n  app.goal_events: {}\n";
        file_put_contents($this->projectDir.'/config/goals.yaml', $goals);
        $settings->get();
        // A different writer changes an unrelated setting after this loader read.
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($original + ['another_writer' => 'new'], 8, 2));
        $normalized = $settings->save(['values' => ['plan_name' => ['paid' => ['label' => ' Paid ']]]]);
        $saved = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame($original + ['another_writer' => 'new', 'bi_glossary' => $normalized], $saved);
        self::assertSame($goals, file_get_contents($this->projectDir.'/config/goals.yaml'));
        self::assertSame(['bi_glossary' => $normalized], Yaml::parse($settings->exportYaml()));
        self::assertSame($normalized, $this->load()->get());
    }

    public function testNestedEnvironmentReplacesWholeBlockAndSaveTouchesOnlyActiveMapping(): void
    {
        $base = ['default_locale' => 'es', 'locales' => ['es'], 'values' => ['device_class' => ['tablet' => ['label' => 'Base']]]];
        $inactive = ['app_host' => 'https://prod.example', 'bi_glossary' => ['locales' => ['en', 'fr']]];
        $this->settings([
            'bi_glossary' => $base, 'updates_branch' => 'master',
            'environments' => ['test' => ['bi_glossary' => [], 'dashboard_enabled' => false], 'prod' => $inactive],
        ]);
        $settings = $this->load();
        self::assertSame('en', $settings->get()['default_locale']);
        self::assertSame([], $settings->get()['values']);
        $saved = $settings->save(['locales' => ['en', 'es']]);
        $file = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame($base, $file['bi_glossary']);
        self::assertSame($inactive, $file['environments']['prod']);
        self::assertSame($saved, $file['environments']['test']['bi_glossary']);
        self::assertFalse($file['environments']['test']['dashboard_enabled']);
    }

    public function testEnvironmentFileReplacesMainFileAndIsTheOnlySaveTarget(): void
    {
        $this->settings(['bi_glossary' => ['locales' => ['en', 'es']], 'app_host' => 'https://main.example']);
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        file_put_contents($this->projectDir.'/config/aggregate_test.yaml', "bi_glossary: {}\napp_host: https://test.example\n");
        $settings = $this->load();
        self::assertSame(['en'], $settings->get()['locales']);
        $settings->save(['locales' => ['en', 'fr']]);
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        $env = Yaml::parseFile($this->projectDir.'/config/aggregate_test.yaml');
        self::assertSame('https://test.example', $env['app_host']);
        self::assertSame(['en', 'fr'], $env['bi_glossary']['locales']);
    }

    private function settings(array $config = []): BiGlossarySettings
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', $config === [] ? "{}\n" : Yaml::dump($config, 8, 2));

        return $this->load();
    }

    private function load(): BiGlossarySettings
    {
        $loader = new AggregateConfigLoader($this->projectDir, 'test');

        return new BiGlossarySettings($loader, new CustomDataSettings($loader), new BuiltinGlossaryCatalog(new Translator('en')), [
            'contact' => ['label' => 'Contact request', 'enabled' => true, 'anonymous' => true],
            'retired' => ['label' => 'Retired goal', 'enabled' => false, 'anonymous' => false],
        ]);
    }
}
