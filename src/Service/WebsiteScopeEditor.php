<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Dashboard and command-line editing of one website or group scope.
 *
 * The page shows the effective settings. A section is written only when the
 * operator chooses to override it; otherwise the broader setting remains.
 */
final class WebsiteScopeEditor
{
    public function __construct(
        private readonly WebsiteScopeCatalog $catalog,
        private readonly WebsiteScopeResolver $resolver,
        private readonly WebsiteConfigManager $websites,
        private readonly AggregateConfigLoader $deployment,
    ) {
    }

    /**
     * @return array{
     *     value: string,
     *     kind: string,
     *     id: ?string,
     *     label: string,
     *     overrides: list<string>,
     *     invalid: bool,
     *     error: ?string,
     *     singular: string,
     *     plural: string,
     *     choices: list<array{value: string, label: string, group: string}>,
     *     route: string,
     *     help: string
     * }
     */
    public function selection(mixed $raw, string $route): array
    {
        $label = $this->catalog->hasLoadError()
            ? ['singular' => WebsiteScopeCatalog::DEFAULT_SINGULAR, 'plural' => WebsiteScopeCatalog::DEFAULT_PLURAL]
            : $this->catalog->label();
        $parsed = $this->parse(is_string($raw) ? $raw : null);
        $error = null;
        if ($parsed === null) {
            $parsed = ['kind' => 'deployment', 'id' => null, 'value' => 'deployment'];
            $error = 'That website scope is not available. Showing the deployment settings.';
        }
        if ($this->catalog->hasLoadError() && $parsed['kind'] !== 'deployment') {
            $parsed = ['kind' => 'deployment', 'id' => null, 'value' => 'deployment'];
            $error = 'Website group configuration is invalid. Correct config/website-scopes.yaml before using a narrower scope.';
        }
        $overrides = [];
        $invalid = false;
        if ($parsed['kind'] !== 'deployment' && $error === null) {
            $kind = $parsed['kind'] === 'group' ? 'groups' : 'websites';
            $overlay = $this->catalog->readOverlay($kind, (string) $parsed['id']);
            $overrides = $overlay['ok'] ? array_values(array_filter(
                array_keys($overlay['values']),
                static fn (string $key): bool => in_array($key, ScopeKeys::allowed(), true),
            )) : [];
            $token = $parsed['kind'] === 'website' ? $this->tokenForSiteId((string) $parsed['id']) : null;
            $view = $parsed['kind'] === 'group'
                ? $this->resolver->forGroup((string) $parsed['id'])
                : ($token === null ? $this->resolver->closed() : $this->resolver->forToken($token));
            $invalid = $view->isScopeInvalid();
            if ($invalid) {
                $overrides = [];
                $error = 'This scope has an invalid setting file, so collection for it is off. Check the sections to keep and save to replace that file.';
            }
        }

        return [
            'value' => $parsed['value'],
            'kind' => $parsed['kind'],
            'id' => $parsed['id'],
            'label' => $this->displayLabel($parsed, $label),
            'overrides' => $overrides,
            'invalid' => $invalid,
            'error' => $error,
            'singular' => $label['singular'],
            'plural' => $label['plural'],
            'choices' => $this->choices($label),
            'route' => $route,
            'help' => $this->help($parsed, $label),
        ];
    }

    /**
     * @param array{kind: string, id: ?string, value: string, invalid?: bool} $selection
     */
    public function configFor(array $selection): AggregateConfigLoader
    {
        if (($selection['invalid'] ?? false) || $selection['kind'] === 'deployment') {
            return $this->deployment;
        }
        if ($selection['kind'] === 'group') {
            return $this->resolver->forGroup((string) $selection['id']);
        }
        $token = $this->tokenForSiteId((string) $selection['id']);

        return $token === null ? $this->resolver->closed() : $this->resolver->forToken($token);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $checked
     */
    public function saveCollection(string $raw, array $values, array $checked): void
    {
        $selection = $this->requireWritable($raw);
        $set = [];
        $remove = [];
        foreach (ScopeKeys::collectionGroups() as $group => $keys) {
            if (in_array($group, $checked, true)) {
                foreach ($keys as $key) {
                    if (!array_key_exists($key, $values)) {
                        throw new \InvalidArgumentException('A selected section was incomplete. Nothing was saved.');
                    }
                    $set[$key] = $values[$key];
                }
                continue;
            }
            array_push($remove, ...$keys);
        }
        $this->catalog->saveOverlay($selection['kind'] === 'group' ? 'groups' : 'websites', (string) $selection['id'], $set, $remove);
    }

    /**
     * @param array<string, mixed> $model
     */
    public function saveModel(string $raw, array $model, bool $override): void
    {
        $selection = $this->requireWritable($raw);
        $kind = $selection['kind'] === 'group' ? 'groups' : 'websites';
        if (!$override) {
            $this->catalog->saveOverlay($kind, (string) $selection['id'], [], ScopeKeys::modelKeys());

            return;
        }
        (new CustomDataSettings($this->deployment))->validate($model);
        $this->catalog->saveOverlay($kind, (string) $selection['id'], $model, []);
    }

    /**
     * The token of the website with this script id, or null when none or
     * several have it: websites that share a token have no settings of their
     * own, as ingestion and the script settings also refuse them.
     */
    public function tokenForSiteId(string $siteId): ?string
    {
        return $this->websites->findOneById($siteId)['token'] ?? null;
    }

    /**
     * @return array{kind: string, id: ?string, value: string}|null
     */
    private function parse(?string $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === 'deployment') {
            return ['kind' => 'deployment', 'id' => null, 'value' => 'deployment'];
        }
        if (preg_match('/^group:([a-z0-9][a-z0-9-]{0,62})$/D', $raw, $match) === 1) {
            return $this->catalog->hasGroup($match[1])
                ? ['kind' => 'group', 'id' => $match[1], 'value' => $raw]
                : null;
        }
        if (preg_match('/^website:([a-f0-9]{24})$/D', $raw, $match) === 1) {
            return $this->tokenForSiteId($match[1]) !== null
                ? ['kind' => 'website', 'id' => $match[1], 'value' => $raw]
                : null;
        }

        return null;
    }

    /**
     * @return array{kind: string, id: ?string, value: string}
     */
    private function requireWritable(string $raw): array
    {
        if ($this->catalog->hasLoadError()) {
            throw new \RuntimeException('Website group configuration is invalid. Correct config/website-scopes.yaml before saving a narrower scope.');
        }
        $selection = $this->parse($raw);
        if ($selection === null || $selection['kind'] === 'deployment') {
            throw new \InvalidArgumentException('Choose a website or group before saving a narrower setting.');
        }

        return $selection;
    }

    /**
     * @param array{singular: string, plural: string} $label
     *
     * @return list<array{value: string, label: string, group: string}>
     */
    private function choices(array $label): array
    {
        $choices = [[
            'value' => 'deployment',
            'label' => 'Every website',
            'group' => 'Deployment',
        ]];
        if ($this->catalog->hasLoadError()) {
            return $choices;
        }
        foreach ($this->catalog->groups() as $id => $group) {
            $choices[] = [
                'value' => 'group:'.$id,
                'label' => $group['name'],
                'group' => $label['plural'],
            ];
        }
        $sites = $this->websites->sites();
        $websitesWithId = [];
        foreach ($sites as $site) {
            $websitesWithId[$site['id']] = ($websitesWithId[$site['id']] ?? 0) + 1;
        }
        foreach ($sites as $site) {
            // Only websites that can be chosen: one that shares its token
            // with another has no settings of its own (see tokenForSiteId()).
            if ($websitesWithId[$site['id']] > 1) {
                continue;
            }
            $groupId = $this->catalog->groupIdForToken($site['token']);
            $suffix = $groupId !== null ? ' · '.$this->catalog->groupName($groupId) : '';
            $choices[] = [
                'value' => 'website:'.$site['id'],
                'label' => $site['name'].$suffix,
                'group' => 'Websites',
            ];
        }

        return $choices;
    }

    /**
     * @param array{kind: string, id: ?string, value: string} $parsed
     * @param array{singular: string, plural: string} $label
     */
    private function displayLabel(array $parsed, array $label): string
    {
        if ($parsed['kind'] === 'group') {
            return (string) $this->catalog->groupName((string) $parsed['id']);
        }
        if ($parsed['kind'] === 'website') {
            $website = $this->websites->findOneById((string) $parsed['id']);
            if ($website !== null) {
                return (string) $website['name'];
            }
        }

        return 'Every website';
    }

    /**
     * @param array{kind: string, id: ?string, value: string} $parsed
     * @param array{singular: string, plural: string} $label
     */
    private function help(array $parsed, array $label): string
    {
        $singular = strtolower($label['singular']);
        if ($parsed['kind'] === 'deployment') {
            return sprintf('These settings apply to every website unless a %s or website sets the same control. A website setting wins over its %s, and a %s wins over the deployment. Environment variables still win where they exist.', $singular, $singular, $singular);
        }
        if ($parsed['kind'] === 'group') {
            return sprintf('Checked sections are saved for this %s and override the deployment. Unchecked sections keep the deployment setting. A website in this %s can still override these values.', $singular, $singular);
        }

        return sprintf('Checked sections are saved for this website and override its %s and the deployment. Unchecked sections keep the broader setting.', $singular);
    }
}
