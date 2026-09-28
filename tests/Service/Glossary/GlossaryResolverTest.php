<?php

declare(strict_types=1);

namespace App\Tests\Service\Glossary;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\GeoIp\GeoArea;
use App\Service\Glossary\BiGlossarySettings;
use App\Service\Glossary\BuiltinGlossaryCatalog;
use App\Service\Glossary\GlossaryResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class GlossaryResolverTest extends TestCase
{
    public function testNoConfigurationPublishesCompleteEnglishListsAndDisabledGoalLabels(): void
    {
        $rows = $this->resolver()->resolve();
        $devices = $this->dimension($rows, 'device_class', 'en');
        self::assertCount(5, $devices);
        self::assertSame('Tablet', $this->row($rows, 'device_class', 'tablet')['label']);
        self::assertSame([10, 20, 30, 40, 50], array_column($devices, 'sort_order'));
        $geography = $this->dimension($rows, 'geo_area', 'en');
        self::assertCount(count(GeoArea::CONTINENT_CODES) + count(Countries::getCountryCodes()), $geography);
        self::assertSame('Retired goal', $this->row($rows, 'goal_event', 'retired')['label']);
        self::assertSame('config', $this->row($rows, 'goal_event', 'retired')['source']);
        foreach ($rows as $row) {
            self::assertNotSame('', $row['label']);
            self::assertSame('en', $row['locale']);
            self::assertSame(1, $row['is_default_locale']);
            self::assertArrayNotHasKey('synced_at', $row);
        }
    }

    public function testCompleteFallbackChainAndIndependentFields(): void
    {
        $resolver = $this->resolver([
            'default_locale' => 'es', 'locales' => ['es', 'fr-CA', 'zh-Hant-TW', 'qzz'],
            'values' => [
                'device_class' => ['tablet' => ['label' => 'Tableta'], 'mobile' => ['label' => 'Móvil']],
                'referrer_channel' => ['social' => [
                    'label' => ['fr-CA' => 'Réseaux sociaux'], 'group' => ['es' => 'Orgánico'],
                ]],
                'event_name' => ['newsletter' => []],
            ],
        ], [
            'fr' => ['value.device_class.tablet.label' => 'Tablette', 'value.referrer_channel.social.label' => 'Réseaux'],
            'zh-Hant' => ['value.device_class.tablet.label' => '平板電腦'],
            'zh' => ['value.device_class.mobile.label' => '手機'],
        ]);
        $rows = $resolver->resolve();

        $social = $this->row($rows, 'referrer_channel', 'social', 'fr-CA');
        self::assertSame('Réseaux sociaux', $social['label']);
        self::assertSame('fr-CA', $social['label_locale']);
        self::assertSame('glossary', $social['source']);
        self::assertSame('Orgánico', $social['group_label']);
        self::assertSame('en', $social['description_locale']);
        self::assertStringContainsString('built-in list of social', $social['description']);
        $tablet = $this->row($rows, 'device_class', 'tablet', 'fr-CA');
        self::assertSame('Tablette', $tablet['label']);
        self::assertSame('fr', $tablet['label_locale']);
        self::assertSame('builtin', $tablet['source']);
        $scriptParent = $this->row($rows, 'device_class', 'tablet', 'zh-Hant-TW');
        self::assertSame('平板電腦', $scriptParent['label']);
        self::assertSame('zh-Hant', $scriptParent['label_locale']);
        $languageParent = $this->row($rows, 'device_class', 'mobile', 'zh-Hant-TW');
        self::assertSame('手機', $languageParent['label']);
        self::assertSame('zh', $languageParent['label_locale']);
        $default = $this->row($rows, 'device_class', 'mobile', 'fr-CA');
        self::assertSame('Móvil', $default['label']);
        self::assertSame('es', $default['label_locale']);
        $english = $this->row($rows, 'device_class', 'desktop', 'fr-CA');
        self::assertSame('Desktop', $english['label']);
        self::assertSame('en', $english['label_locale']);
        $code = $this->row($rows, 'event_name', 'newsletter', 'fr-CA');
        self::assertSame('newsletter', $code['label']);
        self::assertNull($code['label_locale']);
        self::assertNull($code['group_label']);
        self::assertNull($code['description']);
        self::assertNull($code['description_locale']);
        self::assertSame(0, $code['is_default_locale']);
        self::assertSame('Tableta', $this->row($rows, 'device_class', 'tablet', 'qzz')['label']);
    }

    public function testSourceOrderIsGlossaryThenConfigurationThenBuiltinAtEachLocale(): void
    {
        $properties = ['plan' => ['description' => 'Configured property definition.', 'consent_required' => true, 'column' => 'plan_name']];
        $translations = ['en' => [
            'value.goal_event.contact.label' => 'Catalog contact',
            'column.analytics_custom_events_v1.plan_name.description' => 'Catalog property definition.',
        ]];
        $resolver = $this->resolver([], $translations, $properties);
        $base = $resolver->resolve();
        self::assertSame('Contact request', $this->row($base, 'goal_event', 'contact')['label']);
        self::assertSame('config', $this->row($base, 'goal_event', 'contact')['source']);
        self::assertSame('Configured property definition.', $this->row($base, 'analytics_custom_events_v1', 'plan_name', 'en', 'column')['description']);
        $overridden = $resolver->resolve([
            'values' => ['goal_event' => ['contact' => ['label' => 'Glossary contact']]],
            'columns' => ['analytics_custom_events_v1' => ['plan_name' => ['description' => 'Glossary definition.']]],
        ]);
        self::assertSame('Glossary contact', $this->row($overridden, 'goal_event', 'contact')['label']);
        self::assertSame('glossary', $this->row($overridden, 'goal_event', 'contact')['source']);
        self::assertSame('Glossary definition.', $this->row($overridden, 'analytics_custom_events_v1', 'plan_name', 'en', 'column')['description']);
    }

    public function testGoalLabelsAndTextAndNumericPropertyDefinitionsBelongToDefaultLocale(): void
    {
        $rows = $this->resolver([
            'default_locale' => 'es', 'locales' => ['es', 'fr-CA'],
            'columns' => ['analytics_custom_events_v1' => ['revenue_text' => [
                'label' => ['fr-CA' => 'Revenu'], 'description' => ['fr-CA' => 'Définition traduite.'],
            ]]],
        ], [], [
            'revenue' => ['description' => 'Amount before tax.', 'consent_required' => true, 'column' => 'revenue_text', 'numeric_column' => 'revenue_amount', 'type' => 'double'],
        ])->resolve();
        $goal = $this->row($rows, 'goal_event', 'contact', 'fr-CA');
        self::assertSame('Contact request', $goal['label']);
        self::assertSame('es', $goal['label_locale']);
        self::assertSame('config', $goal['source']);
        $text = $this->row($rows, 'analytics_custom_events_v1', 'revenue_text', 'fr-CA', 'column');
        self::assertSame('Revenu', $text['label']);
        self::assertSame('Définition traduite.', $text['description']);
        self::assertSame('fr-CA', $text['description_locale']);
        $numeric = $this->row($rows, 'analytics_custom_events_v1', 'revenue_amount', 'fr-CA', 'column');
        self::assertSame('Amount before tax.', $numeric['description']);
        self::assertSame('es', $numeric['description_locale']);
        self::assertSame('revenue_amount', $numeric['label']);
        self::assertNull($numeric['label_locale']);
        $pageviews = $this->row($rows, 'analytics_custom_pageviews_v1', 'revenue_text', 'fr-CA', 'column');
        self::assertSame('Amount before tax.', $pageviews['description']);
        self::assertSame('es', $pageviews['description_locale']);
    }

    public function testIntlLabelsRecordActualLocaleAndUnknownLocaleFallsBackHonestly(): void
    {
        $rows = $this->resolver(['locales' => ['en', 'fr-CA', 'qzz']])->resolve();
        $french = $this->row($rows, 'geo_area', 'country:DE', 'fr-CA');
        self::assertSame('Allemagne', $french['label']);
        self::assertSame('fr-CA', $french['label_locale']);
        self::assertSame('en', $french['description_locale']);
        $unknown = $this->row($rows, 'geo_area', 'country:DE', 'qzz');
        self::assertSame('Germany', $unknown['label']);
        self::assertSame('en', $unknown['label_locale']);
        $untranslated = $this->row($rows, 'device_class', 'tablet', 'fr-CA');
        self::assertSame('en', $untranslated['label_locale']);
        self::assertSame('Tablet', $untranslated['label']);
    }

    public function testEveryLocaleHasEqualCompleteRowsAndExactlyOneDefaultPerEntry(): void
    {
        $resolver = $this->resolver([
            'locales' => ['en', 'es', 'fr-CA'],
            'values' => ['event_name' => ['newsletter_signup' => ['label' => ['es' => 'Boletín']]], 'plan_name' => ['free' => [], 'paid' => ['label' => 'Paid plan']]],
        ], [], ['plan' => ['description' => 'Published plans.', 'consent_required' => true, 'column' => 'plan_name']]);
        $rows = $resolver->resolve();
        self::assertSame($rows, $resolver->resolve(), 'Repeated resolution must be deterministic for no-op synchronization.');
        $counts = $entries = [];
        foreach ($rows as $row) {
            $subject = $row['entry_type'].':'.$row['subject'];
            $counts[$subject][$row['locale']] = ($counts[$subject][$row['locale']] ?? 0) + 1;
            $entry = $subject.':'.$row['code'];
            $entries[$entry] = ($entries[$entry] ?? 0) + $row['is_default_locale'];
            self::assertNotSame('', $row['label']);
            self::assertSame(0, preg_match('/\p{Cc}/u', $row['label']));
        }
        foreach ($counts as $locales) {
            self::assertCount(3, $locales);
            self::assertCount(1, array_unique(array_values($locales)));
        }
        foreach ($entries as $count) {
            self::assertSame(1, $count);
        }
        self::assertSame('free', $this->row($rows, 'plan_name', 'free')['label']);
    }

    public function testContinentsSortFirstAndCountryOrderUsesLocalizedOrOverriddenLabels(): void
    {
        $rows = $this->resolver([
            'locales' => ['en', 'fr-CA'],
            'values' => ['geo_area' => ['country:DE' => ['label' => ['en' => 'AAA Germany']]]],
        ])->resolve();
        $continents = array_values(array_filter($this->dimension($rows, 'geo_area', 'en'), static fn (array $row): bool => str_starts_with($row['code'], 'continent:')));
        self::assertSame([10, 20, 30, 40, 50, 60, 70], array_column($continents, 'sort_order'));
        self::assertSame(80, $this->row($rows, 'geo_area', 'country:DE')['sort_order']);
        if (extension_loaded('intl')) {
            self::assertLessThan(
                $this->row($rows, 'geo_area', 'country:FR', 'fr-CA')['sort_order'],
                $this->row($rows, 'geo_area', 'country:EC', 'fr-CA')['sort_order'],
                'Équateur sorts under E, before France.',
            );
        }
        $customSort = $this->resolver(['values' => ['geo_area' => ['country:DE' => ['sort' => 17]]]])->resolve();
        self::assertSame(17, $this->row($customSort, 'geo_area', 'country:DE')['sort_order']);
    }

    private function row(array $rows, string $subject, string $code, string $locale = 'en', string $type = 'value'): array
    {
        foreach ($rows as $row) {
            if ($row['entry_type'] === $type && $row['subject'] === $subject && $row['code'] === $code && $row['locale'] === $locale) {
                return $row;
            }
        }
        self::fail('Missing '.$type.' '.$subject.' '.$code.' '.$locale);
    }

    private function dimension(array $rows, string $dimension, string $locale): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => $row['entry_type'] === 'value' && $row['subject'] === $dimension && $row['locale'] === $locale));
    }

    private function resolver(array $glossary = [], array $translations = [], array $properties = []): GlossaryResolver
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('yaml', dirname(__DIR__, 3).'/translations/bi_glossary.en.yaml', 'en', 'bi_glossary');
        foreach ($translations as $locale => $messages) {
            $translator->addResource('array', $messages, $locale, 'bi_glossary');
        }
        $catalog = new BuiltinGlossaryCatalog($translator);
        $loader = $this->createStub(AggregateConfigLoader::class);
        $loader->method('all')->willReturn(['bi_glossary' => $glossary]);
        $custom = $this->createStub(CustomDataSettings::class);
        $custom->method('properties')->willReturn($properties);
        $columns = $numeric = [];
        foreach ($properties as $key => $definition) {
            if (!empty($definition['column'])) {
                $columns[$definition['column']] = $key;
            }
            if (!empty($definition['numeric_column'])) {
                $numeric[$definition['numeric_column']] = ['property' => $key, 'type' => $definition['type']];
            }
        }
        $custom->method('reportingColumns')->willReturn($columns);
        $custom->method('numericReportingColumns')->willReturn($numeric);
        $settings = new BiGlossarySettings($loader, $custom, $catalog, [
            'contact' => ['label' => 'Contact request', 'enabled' => true, 'anonymous' => true],
            'retired' => ['label' => 'Retired goal', 'enabled' => false, 'anonymous' => false],
        ]);

        return new GlossaryResolver($settings, $catalog);
    }
}
