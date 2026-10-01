<?php

declare(strict_types=1);

namespace App\Service\Glossary;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\PrivacySanitizer;
use App\Service\ReportingViewManager;
use App\Service\WebsiteConfigManager;
use Symfony\Component\Yaml\Yaml;

/** Reporting-only configuration; never registered with ingestion validation. */
class BiGlossarySettings
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly CustomDataSettings $customData,
        private readonly BuiltinGlossaryCatalog $catalog,
        private readonly array $goalDefinitions,
        private readonly ?WebsiteConfigManager $websiteConfig = null,
    ) {
    }

    public function get(): array
    {
        $this->config->assertHealthy();
        $all = $this->config->all();

        return $this->validate(array_key_exists('bi_glossary', $all) ? $all['bi_glossary'] : []);
    }

    public function save(mixed $settings): array
    {
        $normalized = $this->validate($settings);
        $this->config->updateMany(static fn (array $current): array => ['bi_glossary' => $normalized]);

        return $normalized;
    }

    public function exportYaml(): string
    {
        return Yaml::dump(['bi_glossary' => $this->get()], 8, 2);
    }

    public function dimensions(): array
    {
        $dimensions = $this->catalog->dimensions();
        $dimensions['goal_event'] = array_keys($this->goalDefinitions);
        $dimensions['website_token'] = array_map('strval', array_keys($this->websites()));
        foreach ($this->customData->reportingColumns() as $column => $key) {
            // Core dimensions always keep their existing meaning.
            $dimensions[$column] ??= [];
        }

        return $dimensions;
    }

    public function coveredColumns(): array
    {
        $views = BuiltinGlossaryCatalog::coveredColumns();
        $customColumns = [...array_keys($this->customData->reportingColumns()), ...array_keys($this->customData->numericReportingColumns())];
        foreach (ReportingViewManager::VIEW_NAMES as $view) {
            $views[$view] = [...$views[$view], ...$customColumns];
        }

        return $views;
    }

    public function properties(): array
    {
        return $this->customData->properties();
    }

    public function goals(): array
    {
        return $this->goalDefinitions;
    }

    /**
     * Registered websites as declared configuration: token => name and domain,
     * cleaned to fit glossary text. Tokens that could not be published as codes
     * are left out.
     *
     * @return array<string, array{name: ?string, domain: ?string}>
     */
    public function websites(): array
    {
        $websites = [];
        foreach ($this->websiteConfig?->getWebsites() ?? [] as $website) {
            $token = $website['token'] ?? null;
            if (!is_string($token) || !self::isSafeCode($token) || isset($websites[$token])) {
                continue;
            }
            $websites[$token] = [
                'name' => self::publishableText($website['name'] ?? null, 191),
                'domain' => self::publishableText($website['domain'] ?? null, 1000),
            ];
        }

        return $websites;
    }

    /** A declared code that is not an enumerated built-in: bounded, trimmed and free of control characters. */
    public static function isSafeCode(string $code): bool
    {
        return $code !== '' && strlen($code) <= 191 && trim($code) === $code
            && preg_match('//u', $code) === 1 && preg_match('/\p{Cc}/u', $code) === 0;
    }

    /** Configuration text trimmed to glossary limits, or null when there is none. */
    private static function publishableText(mixed $text, int $bytes): ?string
    {
        if (!is_string($text) && !is_int($text)) {
            return null;
        }
        $text = trim((string) preg_replace('/\p{Cc}+/u', ' ', (string) $text));
        if ($text === '' || preg_match('//u', $text) !== 1) {
            return null;
        }

        return strlen($text) <= $bytes ? $text : rtrim(mb_strcut($text, 0, $bytes, 'UTF-8'));
    }

    public static function normalizeLocale(mixed $locale): ?string
    {
        if (!is_string($locale) || preg_match('/^([a-z]{2,8})(?:-([a-z]{4}))?(?:-([a-z]{2}|[0-9]{3}))?$/iD', $locale, $parts) !== 1) {
            return null;
        }

        return strtolower($parts[1])
            .(!empty($parts[2]) ? '-'.ucfirst(strtolower($parts[2])) : '')
            .(!empty($parts[3]) ? '-'.strtoupper($parts[3]) : '');
    }

    public function validate(mixed $raw): array
    {
        $errors = [];
        if (!$this->isMapping($raw)) {
            throw new GlossaryValidationException(['bi_glossary' => 'Must be a mapping.']);
        }
        $this->unknownKeys($raw, ['default_locale', 'locales', 'values', 'columns'], 'bi_glossary', $errors);
        $default = self::normalizeLocale($raw['default_locale'] ?? 'en');
        if ($default === null || (array_key_exists('default_locale', $raw) && $raw['default_locale'] === null)) {
            $errors['bi_glossary.default_locale'] = 'Use a language, optional script and optional region, such as en, zh-Hant or fr-CA.';
            $default = 'en';
        }
        $locales = [];
        $inputLocales = array_key_exists('locales', $raw) ? $raw['locales'] : ['en'];
        if (!is_array($inputLocales) || !array_is_list($inputLocales) || count($inputLocales) < 1 || count($inputLocales) > 20) {
            $errors['bi_glossary.locales'] = 'Provide a list of 1 to 20 published locales.';
        } else {
            foreach ($inputLocales as $index => $inputLocale) {
                $locale = self::normalizeLocale($inputLocale);
                if ($locale === null) {
                    $errors['bi_glossary.locales.'.$index] = 'Invalid locale; use hyphens, for example es-MX.';
                } elseif (in_array($locale, $locales, true)) {
                    $errors['bi_glossary.locales.'.$index] = 'Duplicate locale.';
                } else {
                    $locales[] = $locale;
                }
            }
        }
        if (!in_array($default, $locales, true)) {
            $errors['bi_glossary.locales'] = 'Published locales must contain default_locale.';
        }
        $normalized = ['default_locale' => $default, 'locales' => $locales, 'values' => [], 'columns' => []];
        $dimensions = $this->dimensions();
        $views = $this->coveredColumns();
        $builtinDimensions = $this->catalog->dimensions();
        foreach (['values' => 2000, 'columns' => 500] as $section => $maximum) {
            $entries = array_key_exists($section, $raw) ? $raw[$section] : [];
            if (!$this->isMapping($entries)) {
                $errors['bi_glossary.'.$section] = 'Must be a mapping.';
                continue;
            }
            $count = 0;
            foreach ($entries as $subject => $codes) {
                $path = 'bi_glossary.'.$section.'.'.$subject;
                $known = $section === 'values' ? $dimensions : $views;
                if (!array_key_exists($subject, $known)) {
                    $errors[$path] = $section === 'values' ? 'Unknown reporting dimension.' : 'Unknown glossary-covered view.';
                    continue;
                }
                // Numeric scalar codes are legitimate YAML keys (including 0).
                if (!is_array($codes)) {
                    $errors[$path] = 'Must map codes or columns to definitions.';
                    continue;
                }
                $count += count($codes);
                foreach ($codes as $rawCode => $definition) {
                    $code = (string) $rawCode;
                    $entryPath = $path.'.'.$code;
                    if ($section === 'columns') {
                        $validCode = in_array($code, $views[$subject], true);
                    } elseif ($subject === 'event_name') {
                        $validCode = PrivacySanitizer::isSafeEventName($code);
                    } elseif ($subject === 'website_token') {
                        // Registered websites are listed already; another token
                        // keeps labeling a removed website's retained history.
                        $validCode = self::isSafeCode($code);
                    } elseif (array_key_exists($subject, $builtinDimensions)) {
                        $validCode = in_array($code, $dimensions[$subject], true);
                    } else {
                        $validCode = self::isSafeCode($code);
                    }
                    if (!$validCode) {
                        $errors[$entryPath] = 'Unknown or invalid code or column; use an existing declared code, or a bounded safe custom value.';
                    }
                    if (!$this->isMapping($definition)) {
                        $errors[$entryPath] = 'Must be a mapping.';
                        continue;
                    }
                    $allowed = $section === 'values' ? ['label', 'group', 'description', 'sort'] : ['label', 'description', 'sort'];
                    $this->unknownKeys($definition, $allowed, $entryPath, $errors);
                    $clean = [];
                    foreach (['label' => 191, 'group' => 191, 'description' => 1000] as $field => $length) {
                        if (!array_key_exists($field, $definition) || !in_array($field, $allowed, true)) {
                            continue;
                        }
                        $text = $definition[$field];
                        if (is_string($text)) {
                            $text = [$default => $text];
                        }
                        if (!$this->isMapping($text)) {
                            $errors[$entryPath.'.'.$field] = 'Must be text or a mapping of published locales to text.';
                            continue;
                        }
                        $clean[$field] = [];
                        foreach ($text as $textLocale => $value) {
                            $locale = self::normalizeLocale($textLocale);
                            $fieldPath = $entryPath.'.'.$field.'.'.$textLocale;
                            if ($locale === null || !in_array($locale, $locales, true)) {
                                $errors[$fieldPath] = 'Locale must be one of the published locales.';
                            } elseif (isset($clean[$field][$locale])) {
                                $errors[$fieldPath] = 'Duplicate normalized locale.';
                            } elseif (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $length || !$this->cleanText($value)) {
                                $errors[$fieldPath] = 'Must be non-empty UTF-8 text without control characters, at most '.$length.' bytes.';
                            } else {
                                $clean[$field][$locale] = trim($value);
                            }
                        }
                    }
                    if (array_key_exists('sort', $definition)) {
                        if (!is_int($definition['sort']) || $definition['sort'] < 0 || $definition['sort'] > 100000) {
                            $errors[$entryPath.'.sort'] = 'Must be an integer from 0 to 100000.';
                        } else {
                            $clean['sort'] = $definition['sort'];
                        }
                    }
                    $normalized[$section][$subject][$code] = $clean;
                }
            }
            if ($count > $maximum) {
                $errors['bi_glossary.'.$section] = 'At most '.$maximum.' entries may be declared.';
            }
        }
        if ($errors !== []) {
            throw new GlossaryValidationException($errors);
        }

        return $normalized;
    }

    private function cleanText(string $text): bool
    {
        return preg_match('//u', $text) === 1 && preg_match('/\p{Cc}/u', $text) === 0;
    }

    private function isMapping(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private function unknownKeys(array $value, array $allowed, string $path, array &$errors): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $key) {
            $errors[$path.'.'.$key] = 'Unsupported option.';
        }
    }
}
