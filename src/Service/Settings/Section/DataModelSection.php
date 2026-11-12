<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\DataModelViews;
use App\Service\ScopeKeys;
use App\Service\Settings\AbstractSection;
use App\Service\Settings\SettingsWithScopeOverrides;
use App\Service\WebsiteScopeEditor;

/**
 * The data model, as the Data model page saves it: custom properties, query
 * parameter mappings, page depth and query persistence, checked as a whole.
 * Saving the deployment's model updates the reporting views as the page
 * does. With --scope a website or group gets its own model, which the
 * reporting views follow only once its columns are published
 * (app:website-scopes publish-model); unset gives the scope the broader
 * model back.
 */
final class DataModelSection extends AbstractSection implements SettingsWithScopeOverrides
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly CustomDataSettings $settings,
        private readonly DataModelViews $views,
        private readonly WebsiteScopeEditor $scopes,
    ) {
    }

    public function name(): string
    {
        return 'data-model';
    }

    public function page(): string
    {
        return 'Collection > Data model';
    }

    public function target(): ?string
    {
        return self::TARGET_SCOPE;
    }

    public function keys(): array
    {
        return ScopeKeys::modelKeys();
    }

    public function values(?string $target = null): array
    {
        $selection = $this->selection($target);
        $config = $selection['kind'] === 'deployment' ? $this->config : $this->scopes->configFor($selection);

        return (new CustomDataSettings($config))->toArray();
    }

    public function environmentOverrides(?string $target = null): array
    {
        $this->selection($target);

        return [];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertKnown($changes);
        $selection = $this->selection($target);
        $model = [...$this->values($target), ...$changes];
        $this->settings->validate($model);
        if ($check) {
            return [self::done(true, '')];
        }
        if ($selection['kind'] !== 'deployment') {
            $this->scopes->saveModel($selection['value'], $model, true);

            return ['Data model saved for '.$selection['label'].'. Collection uses it on future events. Reporting views still follow the deployment model until you publish its columns (app:website-scopes publish-model).'];
        }
        $this->settings->save($model);

        return array_column($this->views->apply('Data model saved. Collection changes apply to future events.'), 1);
    }

    public function unset(array $keys, string $target, bool $check = false): string
    {
        $this->assertKnown(array_fill_keys($keys, null));
        $selection = $this->selection($target);
        if ($selection['kind'] === 'deployment') {
            throw new \InvalidArgumentException('Only a website or group has its own model to remove: name it with --scope.');
        }
        if (!$check) {
            $this->scopes->saveModel($selection['value'], [], false);
        }

        return self::done($check, $selection['label'].' now follows the broader data model. A website or group overrides the whole model, never single keys.');
    }

    /** @return array{kind: string, id: ?string, value: string, label: string, overrides: list<string>, invalid: bool, error: ?string} */
    private function selection(?string $target): array
    {
        $selection = $this->scopes->selection($target ?? 'deployment', 'app_data_model');
        if ($selection['error'] !== null && $selection['kind'] === 'deployment' && $target !== null && $target !== 'deployment') {
            throw new \InvalidArgumentException('That scope is not available: use website:<script id>, group:<id> or deployment. '.$selection['error']);
        }

        return $selection;
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
