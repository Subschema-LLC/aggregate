<?php

declare(strict_types=1);

namespace App\Tests\Service\Glossary;

use App\Service\GeoIp\GeoArea;
use App\Service\Glossary\BuiltinGlossaryCatalog;
use App\Service\PrivacySanitizer;
use App\Service\ReportingViewManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

final class BuiltinGlossaryCatalogTest extends TestCase
{
    public function testCompleteEnglishTextForEveryDeclaredCodeAndCoveredColumn(): void
    {
        $catalog = $this->catalog();
        foreach (['value' => $catalog->dimensions(), 'column' => $catalog::coveredColumns()] as $type => $subjects) {
            foreach ($subjects as $subject => $codes) {
                foreach ($codes as $code) {
                    foreach (['label' => 191, 'description' => 1000] as $field => $limit) {
                        $text = $catalog->text($type, $subject, $code, $field, 'en');
                        $key = $type.'.'.$subject.'.'.$code.'.'.$field;
                        self::assertIsString($text, $key);
                        self::assertNotSame('', trim($text), $key);
                        self::assertLessThanOrEqual($limit, strlen($text), $key);
                        self::assertDoesNotMatchRegularExpression('/\p{Cc}/u', $text, $key);
                    }
                }
            }
        }
    }

    public function testCatalogContainsNoUndeclaredCodeOrColumnKeys(): void
    {
        $catalog = $this->catalog();
        $valid = ['value.geo_area.country.description' => true];
        foreach (['value' => $catalog->dimensions(), 'column' => $catalog::coveredColumns()] as $type => $subjects) {
            foreach ($subjects as $subject => $codes) {
                foreach ($codes as $code) {
                    foreach (['label', 'description', 'group'] as $field) {
                        $valid[$type.'.'.$subject.'.'.$code.'.'.$field] = true;
                    }
                }
            }
        }
        foreach (Yaml::parseFile(dirname(__DIR__, 3).'/translations/bi_glossary.en.yaml') as $key => $text) {
            self::assertArrayHasKey($key, $valid, 'Catalog key has no declared code or column: '.$key);
            self::assertIsString($text, $key);
        }
    }

    public function testCodeListsAndCustomBaseColumnsStaySingleSourced(): void
    {
        $catalog = $this->catalog();
        $dimensions = $catalog->dimensions();
        self::assertSame(PrivacySanitizer::DEVICE_CLASSES, $dimensions['device_class']);
        self::assertSame(PrivacySanitizer::VIEWPORT_BUCKETS, $dimensions['viewport_bucket']);
        self::assertSame(PrivacySanitizer::REFERRER_CHANNELS, $dimensions['referrer_channel']);
        self::assertSame([
            ...array_map(static fn (string $code): string => 'continent:'.$code, GeoArea::CONTINENT_CODES),
            ...array_map(static fn (string $code): string => 'country:'.$code, Countries::getCountryCodes()),
        ], $dimensions['geo_area']);
        foreach (ReportingViewManager::VIEW_NAMES as $view) {
            self::assertSame(ReportingViewManager::BUILTIN_COLUMNS, $catalog::coveredColumns()[$view]);
        }
    }

    public function testTranslatorFallbackDoesNotPreemptResolverFallback(): void
    {
        $catalog = $this->catalog();
        self::assertSame('Tablet', $catalog->text('value', 'device_class', 'tablet', 'label', 'en'));
        self::assertNull($catalog->text('value', 'device_class', 'tablet', 'label', 'fr-CA'));
        self::assertNull($catalog->text('value', 'device_class', 'tablet', 'label', 'qzz'));
    }

    public function testIntlCountryNamesAreLocalizedOnlyForKnownLocales(): void
    {
        $catalog = $this->catalog();
        self::assertSame('Allemagne', $catalog->text('value', 'geo_area', 'country:DE', 'label', 'fr-CA'));
        self::assertSame('Germany', $catalog->text('value', 'geo_area', 'country:DE', 'label', 'en'));
        self::assertNull($catalog->text('value', 'geo_area', 'country:DE', 'label', 'qzz'));
        self::assertNull($catalog->text('value', 'geo_area', 'country:DE', 'description', 'fr-CA'));
    }

    private function catalog(): BuiltinGlossaryCatalog
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 3).'/translations/bi_glossary.en.yaml', 'en', 'bi_glossary');

        return new BuiltinGlossaryCatalog($translator);
    }
}
