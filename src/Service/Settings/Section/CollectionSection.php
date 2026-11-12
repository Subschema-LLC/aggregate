<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\BotTrafficSettings;
use App\Service\CollectionControlsForm;
use App\Service\CollectionProfile;
use App\Service\CustomDataSettings;
use App\Service\ScopeKeys;
use App\Service\Settings\AbstractSection;
use App\Service\Settings\SettingsWithScopeOverrides;
use App\Service\TrackingFailureSettings;
use App\Service\WebsiteScopeEditor;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Collection controls, through the page's own form: the profile, anonymous
 * collection and its exclusions, geography, Google Consent Mode, bot traffic
 * and the retry of failed enhanced events. With --scope=website:<script id>
 * or --scope=group:<id> the settings are saved for that website or group, as
 * the page's scope menu does: the sections it already overrides stay, a
 * section with a changed key is added, and the rest follow the broader
 * setting. The retry settings belong to the whole installation.
 */
final class CollectionSection extends AbstractSection implements SettingsWithScopeOverrides
{
    private const RETRY = [TrackingFailureSettings::KEY_ENABLED, TrackingFailureSettings::KEY_BATCH_SIZE];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly TrackingFailureSettings $retry,
        private readonly WebsiteScopeEditor $scopes,
    ) {
    }

    public function name(): string
    {
        return 'collection';
    }

    public function page(): string
    {
        return 'Collection > Collection controls';
    }

    public function target(): string
    {
        return self::TARGET_SCOPE;
    }

    public function keys(): array
    {
        return [...array_merge(...array_values(ScopeKeys::collectionGroups())), ...self::RETRY];
    }

    public function values(?string $target = null): array
    {
        $config = $this->configFor($this->selection($target));
        $paths = $config->getWithEnvFallback('anonymous_excluded_paths', []);
        if (is_string($paths)) {
            $paths = preg_split('/[\r\n,]+/', $paths) ?: [];
        }
        $geoLevel = $config->getWithEnvFallback('anonymous_geo_level', 'macro_region');
        $geoLevel = is_string($geoLevel) ? strtolower(trim($geoLevel)) : 'macro_region';
        $geoPath = $config->getWithEnvFallback('anonymous_geo_database_path', '');
        $bots = new BotTrafficSettings($config);
        try {
            $retry = $this->retry->toArray();
        } catch (\Throwable) {
            $retry = TrackingFailureSettings::defaults();
        }

        return [
            CollectionProfile::KEY => (new CollectionProfile($config))->name(),
            'anonymous_tracking_enabled' => $config->getBoolWithEnvFallback('anonymous_tracking_enabled', true),
            'anonymous_excluded_paths' => is_array($paths) ? array_values(array_filter($paths, 'is_string')) : [],
            'anonymous_geo_enabled' => $config->getBoolWithEnvFallback('anonymous_geo_enabled', false),
            'anonymous_geo_level' => in_array($geoLevel, CollectionControlsForm::GEO_LEVELS, true) ? $geoLevel : 'macro_region',
            'anonymous_geo_database_path' => is_string($geoPath) ? trim($geoPath) : '',
            CustomDataSettings::GOOGLE_CONSENT_MODE_KEY => (new CustomDataSettings($config))->googleConsentMode(),
            BotTrafficSettings::KEY => $bots->mode(),
            BotTrafficSettings::USER_AGENTS_KEY => $bots->userAgents(),
            ...$retry,
        ];
    }

    public function environmentOverrides(?string $target = null): array
    {
        $config = $this->configFor($this->selection($target));
        $overridden = array_values(array_filter($this->keys(), static fn (string $key): bool => $config->hasEnvironmentOverride($key)));
        try {
            array_push($overridden, ...array_keys(array_filter($this->retry->getEnvironmentOverrides())));
        } catch (\Throwable) {
        }

        return array_values(array_unique($overridden));
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertKnown($changes);
        $selection = $this->selection($target);
        $scoped = $selection['kind'] !== 'deployment';
        if ($scoped && array_intersect(array_keys($changes), self::RETRY) !== []) {
            throw new \InvalidArgumentException('The retry of failed enhanced events belongs to the whole installation: leave out --scope for '.implode(' and ', self::RETRY).'.');
        }
        $config = $this->configFor($selection);
        $form = CollectionControlsForm::fromSubmission(
            new ParameterBag(self::fields([...$this->values($target), ...$changes], $scoped)),
            new CustomDataSettings($config),
            $scoped ? null : new BotTrafficSettings($config),
        );

        if ($scoped) {
            $values = $form['settings'];
            $all = [...$this->values($target), ...$changes];
            $values[CustomDataSettings::GOOGLE_CONSENT_MODE_KEY] = CustomDataSettings::normalizeGoogleConsentMode($all[CustomDataSettings::GOOGLE_CONSENT_MODE_KEY])
                ?? throw new \InvalidArgumentException('Google Consent Mode signals must be one of '.implode(', ', CustomDataSettings::GOOGLE_CONSENT_MODES).'.');
            $values[BotTrafficSettings::KEY] = BotTrafficSettings::normalizeMode($all[BotTrafficSettings::KEY])
                ?? throw new \InvalidArgumentException('Bot traffic must be label or drop.');
            $values[BotTrafficSettings::USER_AGENTS_KEY] = BotTrafficSettings::normalizeUserAgents(is_array($all[BotTrafficSettings::USER_AGENTS_KEY]) ? $all[BotTrafficSettings::USER_AGENTS_KEY] : [])
                ?? throw new \InvalidArgumentException('The bot phrase list is not valid.');
            $checked = [];
            foreach (ScopeKeys::collectionGroups() as $group => $keys) {
                if (array_intersect($keys, $selection['overrides']) !== [] || array_intersect($keys, array_keys($changes)) !== []) {
                    $checked[] = $group;
                }
            }
            if (!$check) {
                $this->scopes->saveCollection($selection['value'], $values, $checked);
            }

            return [self::done($check, 'Saved for '.$selection['label'].'. Sections it does not override keep the broader setting.')];
        }
        if ($check) {
            TrackingFailureSettings::validate([...$this->retry->toArray(), ...$form['retry']]);

            return [self::done(true, '')];
        }

        $this->config->setMany($form['settings']);
        $this->retry->save($form['retry']);
        $notes = ['Analytics collection settings updated successfully.'];
        $profile = new CollectionProfile($this->config);
        if ($profile->hasEnvironmentOverride() && $profile->name() !== $form['collection_profile']) {
            $notes[] = sprintf('The COLLECTION_PROFILE environment variable keeps the %s profile in effect. The saved YAML value applies only when that variable is removed.', $profile->name());
        }
        $bots = new BotTrafficSettings($this->config);
        if (isset($form['settings'][BotTrafficSettings::KEY]) && $bots->hasEnvironmentOverride() && $bots->mode() !== $form['settings'][BotTrafficSettings::KEY]) {
            $notes[] = sprintf('The BOT_TRAFFIC environment variable keeps bot traffic set to %s. The saved YAML value applies only when that variable is removed.', $bots->mode());
        }
        $geoPath = $this->config->getWithEnvFallback('anonymous_geo_database_path', '');
        if ($this->config->getBoolWithEnvFallback('anonymous_geo_enabled', false) && !$profile->isStrict() && (!is_string($geoPath) || trim($geoPath) === '')) {
            $notes[] = 'Coarse geography is enabled without a local GeoIP database path. Events will continue with no geography until one is configured.';
        }

        return $notes;
    }

    /**
     * Removes a website's or group's own value for the sections holding these
     * keys, so they follow the broader setting again, as unticking a section on
     * the page does.
     *
     * @param list<string> $keys
     */
    public function unset(array $keys, string $target, bool $check = false): string
    {
        $this->assertKnown(array_fill_keys($keys, null));
        $selection = $this->selection($target);
        if ($selection['kind'] === 'deployment') {
            throw new \InvalidArgumentException('Only a website or group has its own values to remove: name it with --scope.');
        }
        $values = $this->values($target);
        $checked = [];
        foreach (ScopeKeys::collectionGroups() as $group => $groupKeys) {
            if (array_intersect($groupKeys, $selection['overrides']) !== [] && array_intersect($groupKeys, $keys) === []) {
                $checked[] = $group;
            }
        }
        if (!$check) {
            $this->scopes->saveCollection($selection['value'], $values, $checked);
        }

        return self::done($check, $selection['label'].' now follows the broader setting for '.implode(', ', $keys).' and the rest of their sections.');
    }

    /**
     * The page's form fields for a set of values.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private static function fields(array $values, bool $scoped): array
    {
        $bool = static fn (mixed $value): string => in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true) ? '1' : '0';
        $lines = static fn (mixed $value): string => is_array($value) ? implode("\n", array_map('strval', $value)) : (string) $value;
        $fields = [
            CollectionProfile::KEY => (string) $values[CollectionProfile::KEY],
            'anonymous_tracking_enabled' => $bool($values['anonymous_tracking_enabled']),
            'anonymous_excluded_paths' => $lines($values['anonymous_excluded_paths']),
            'anonymous_geo_enabled' => $bool($values['anonymous_geo_enabled']),
            'anonymous_geo_level' => (string) $values['anonymous_geo_level'],
            'anonymous_geo_database_path' => (string) $values['anonymous_geo_database_path'],
            CustomDataSettings::GOOGLE_CONSENT_MODE_KEY => (string) $values[CustomDataSettings::GOOGLE_CONSENT_MODE_KEY],
            BotTrafficSettings::KEY => (string) $values[BotTrafficSettings::KEY],
            BotTrafficSettings::USER_AGENTS_KEY => $lines($values[BotTrafficSettings::USER_AGENTS_KEY]),
        ];
        if (!$scoped) {
            $fields[TrackingFailureSettings::KEY_ENABLED] = $bool($values[TrackingFailureSettings::KEY_ENABLED]);
            $fields[TrackingFailureSettings::KEY_BATCH_SIZE] = (string) $values[TrackingFailureSettings::KEY_BATCH_SIZE];
        }

        return $fields;
    }

    /** @return array{kind: string, id: ?string, value: string, label: string, overrides: list<string>, invalid: bool, error: ?string} */
    private function selection(?string $target): array
    {
        $selection = $this->scopes->selection($target ?? 'deployment', 'app_collection_settings');
        if ($selection['error'] !== null && $selection['kind'] === 'deployment' && $target !== null && $target !== 'deployment') {
            throw new \InvalidArgumentException('That scope is not available: use website:<script id>, group:<id> or deployment. '.$selection['error']);
        }

        return $selection;
    }

    /** @param array{kind: string, id: ?string, value: string, invalid?: bool} $selection */
    private function configFor(array $selection): AggregateConfigLoader
    {
        return $selection['kind'] === 'deployment' ? $this->config : $this->scopes->configFor($selection);
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
