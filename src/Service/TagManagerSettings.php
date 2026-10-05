<?php

declare(strict_types=1);

namespace App\Service;

/** Website script settings, with a legacy deployment-wide configuration. */
final class TagManagerSettings
{
    public const MAX_TAGS = 20;
    public const DEFAULTS = ['enabled' => false, 'variables' => [], 'tags' => []];

    /**
     * @param ?string $codeCacheDirectory remembers custom JavaScript that passed its
     *   checks, so serving a website's script does not parse it on every request
     */
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ?SiteScriptConfig $sites = null,
        private readonly ?FeatureFlags $features = null,
        private readonly ?string $codeCacheDirectory = null,
    ) {
    }

    /** @return array{enabled: bool, tags: list<array>} */
    public function all(?string $siteId = null): array
    {
        $config = $this->configuration($siteId);
        $config->assertHealthy();
        $values = $config->all();

        return self::validate(array_key_exists('tag_manager', $values) ? $values['tag_manager'] : self::DEFAULTS, $this->codeCacheDirectory);
    }

    /** Whether this installation serves custom JavaScript tags (the custom_scripts feature flag). */
    public function customScriptsEnabled(): bool
    {
        return $this->features === null || $this->features->isEnabled(TagManagerCustomCode::FEATURE);
    }

    public function save(array $submitted, ?string $siteId = null): void
    {
        $validated = self::validate($submitted, $this->codeCacheDirectory);
        $config = $this->configuration($siteId);
        if ($siteId !== null) {
            $this->sites->ensureDirectory();
        }
        $config->updateMany(static function (array $current) use ($validated): array {
            // Do not replace malformed existing configuration with defaults.
            self::validate(array_key_exists('tag_manager', $current) ? $current['tag_manager'] : self::DEFAULTS);

            return ['tag_manager' => $validated];
        });
    }

    /**
     * Only enabled, explicitly public script definitions reach the browser.
     * Custom JavaScript is compiled into the script separately (see
     * customScripts()); its definition here carries no code.
     */
    public function toBrowserConfig(?string $siteId = null): array
    {
        $settings = $this->all($siteId);
        $tags = $this->servedTags($settings);
        $configuration = [
            'enabled' => $settings['enabled'],
            'variables' => $settings['enabled'] ? $settings['variables'] : [],
            'tags' => array_values(array_map(static fn (array $tag): array => array_diff_key($tag, ['enabled' => true, 'code' => true]), $tags)),
        ];
        if (in_array('custom', array_column($tags, 'type'), true)) {
            // Lets custom code send events with tag.emit() through the tracker's namespace.
            $namespace = $this->config->getWithEnvFallback('js_namespace', 'Aggregate');
            $configuration['namespace'] = is_string($namespace) && preg_match('/^[A-Za-z_$][A-Za-z0-9_$]{0,63}$/D', $namespace) === 1 ? $namespace : 'Aggregate';
        }

        return $configuration;
    }

    /** @return array<string, string> Tag ID => code of the custom JavaScript served with the tags. */
    public function customScripts(?string $siteId = null): array
    {
        $scripts = [];
        foreach ($this->servedTags($this->all($siteId)) as $tag) {
            if ($tag['type'] === 'custom') {
                $scripts[$tag['id']] = $tag['code'];
            }
        }

        return $scripts;
    }

    /** @return list<array> Tags a visitor's browser receives: enabled, with custom JavaScript only when allowed. */
    private function servedTags(array $settings): array
    {
        if (!$settings['enabled']) {
            return [];
        }
        $custom = $this->customScriptsEnabled();
        $served = array_filter($settings['tags'], static fn (array $tag): bool => $tag['enabled'] && ($custom || $tag['type'] !== 'custom'));
        // A tag set to run after one that is not served would never run.
        do {
            $ids = array_flip(array_column($served, 'id'));
            $before = count($served);
            $served = array_filter($served, static fn (array $tag): bool => !isset($tag['after']) || isset($ids[$tag['after']]));
        } while (count($served) !== $before);

        return array_values($served);
    }

    /** @return list<string> Categories needed by the tracker and enabled tags. */
    public function consentCategories(?string $siteId = null): array
    {
        $categories = ['analytics' => true];
        foreach ($this->servedTags($this->all($siteId)) as $tag) {
            if ($tag['consent'] !== 'none') {
                $categories[$tag['consent']] = true;
            }
        }
        unset($categories['analytics']);
        $names = array_keys($categories);
        sort($names);

        return ['analytics', ...$names];
    }

    private function configuration(?string $siteId): AggregateConfigLoader
    {
        if ($siteId === null) {
            return $this->config;
        }
        if ($this->sites === null) {
            throw new \InvalidArgumentException('Website script configuration is unavailable.');
        }

        return $this->sites->configuration($siteId);
    }

    /** @return array{enabled: bool, tags: list<array>} */
    public static function validate(mixed $submitted, ?string $codeCacheDirectory = null): array
    {
        if (!is_array($submitted) || array_diff(array_keys($submitted), ['enabled', 'variables', 'tags']) !== []) {
            throw new \InvalidArgumentException('tag_manager must contain only enabled, variables and tags.');
        }
        $enabled = array_key_exists('enabled', $submitted) ? $submitted['enabled'] : false;
        $tags = array_key_exists('tags', $submitted) ? $submitted['tags'] : [];
        $variables = TagManagerVariables::validate(array_key_exists('variables', $submitted) ? $submitted['variables'] : []);
        if (!is_bool($enabled)) {
            throw new \InvalidArgumentException('tag_manager.enabled must be a YAML boolean (true or false).');
        }
        if (!is_array($tags) || !array_is_list($tags) || count($tags) > self::MAX_TAGS) {
            throw new \InvalidArgumentException('tag_manager.tags must be a list of at most '.self::MAX_TAGS.' scripts.');
        }

        $validated = [];
        $ids = [];
        $sources = [];
        $codeBytes = 0;
        foreach ($tags as $tag) {
            if (!is_array($tag) || array_diff(array_keys($tag), ['id', 'type', 'src', 'method', 'args', 'code', 'enabled', 'consent', 'trigger', 'after']) !== []) {
                throw new \InvalidArgumentException('Each tag must contain only its ID, action, enabled setting, consent, trigger and, optionally, the tag it runs after.');
            }
            $id = $tag['id'] ?? null;
            $type = array_key_exists('type', $tag) ? $tag['type'] : 'script';
            $tagEnabled = array_key_exists('enabled', $tag) ? $tag['enabled'] : true;
            $consent = array_key_exists('consent', $tag) ? $tag['consent'] : 'analytics';
            $trigger = self::validateTrigger(array_key_exists('trigger', $tag) ? $tag['trigger'] : ['type' => 'dom_ready']);
            if (!is_string($id) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $id) !== 1 || isset($ids[$id])) {
                throw new \InvalidArgumentException('Each tag needs a unique ID of 1–64 letters, digits, underscores or hyphens, beginning with a letter.');
            }
            if (!is_bool($tagEnabled)) {
                throw new \InvalidArgumentException('Each tag enabled setting must be a YAML boolean (true or false).');
            }
            if (!is_string($consent) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $consent) !== 1) {
                throw new \InvalidArgumentException('Each tag consent category must contain 1–32 lowercase letters, digits, underscores or hyphens, beginning with a letter. Use none only for a tag that needs no consent.');
            }
            if ($type === 'script') {
                if (array_key_exists('method', $tag) || array_key_exists('args', $tag) || array_key_exists('code', $tag)) {
                    throw new \InvalidArgumentException('A script tag uses src; method and args belong to a call tag, and code to a custom tag.');
                }
                $src = TagManagerVariables::validateSource($tag['src'] ?? null, $variables);
                if (isset($sources[$src])) {
                    throw new \InvalidArgumentException('Each script tag needs a unique HTTPS URL template.');
                }
                $sources[$src] = true;
                $action = ['src' => $src];
            } elseif ($type === 'call') {
                if (array_key_exists('src', $tag) || array_key_exists('code', $tag)) {
                    throw new \InvalidArgumentException('A call tag uses method and args; src belongs to a script tag, and code to a custom tag.');
                }
                $action = [
                    'method' => TagManagerValues::validateMethod($tag['method'] ?? null),
                    'args' => TagManagerValues::validateArguments(array_key_exists('args', $tag) ? $tag['args'] : [], $variables),
                ];
            } elseif ($type === 'custom') {
                if (array_key_exists('src', $tag) || array_key_exists('method', $tag) || array_key_exists('args', $tag)) {
                    throw new \InvalidArgumentException('A custom tag uses code; src belongs to a script tag, and method and args to a call tag.');
                }
                $code = TagManagerCustomCode::validate($tag['code'] ?? null, 'Tag '.$id.': the custom JavaScript', $codeCacheDirectory);
                $codeBytes += strlen($code);
                if ($codeBytes > TagManagerCustomCode::MAX_TOTAL_BYTES) {
                    throw new \InvalidArgumentException(sprintf('Custom JavaScript for one website may total at most %s bytes, because every page downloads it with the tag manager. Load larger code with tag.loadScript().', number_format(TagManagerCustomCode::MAX_TOTAL_BYTES)));
                }
                $action = ['code' => $code];
            } else {
                throw new \InvalidArgumentException('A tag type must be script, call or custom.');
            }
            $ids[$id] = true;
            $entry = ['id' => $id, ...$action, 'enabled' => $tagEnabled, 'consent' => $consent, 'trigger' => $trigger, 'type' => $type];
            if (array_key_exists('after', $tag)) {
                $entry['after'] = $tag['after'];
            }
            $validated[] = $entry;
        }

        return ['enabled' => $enabled, 'variables' => $variables, 'tags' => self::validateChains($validated)];
    }

    /**
     * Tag chaining ("Run after") makes a tag wait until another tag has finished: its script
     * loaded, or its method call or custom code done. That tag must run once
     * per page, and chains cannot loop.
     *
     * @param list<array> $tags
     * @return list<array>
     */
    private static function validateChains(array $tags): array
    {
        $byId = array_column($tags, null, 'id');
        foreach ($tags as $tag) {
            if (!array_key_exists('after', $tag)) {
                continue;
            }
            $after = $tag['after'];
            if (!is_string($after)) {
                throw new \InvalidArgumentException(sprintf('Tag %s: tag chaining must name the ID of another tag to run after.', $tag['id']));
            }
            if (!isset($byId[$after])) {
                throw new \InvalidArgumentException(sprintf('Tag %s runs after %s, but there is no tag with that ID.', $tag['id'], $after));
            }
            if ($after === $tag['id']) {
                throw new \InvalidArgumentException(sprintf('Tag %s cannot run after itself.', $tag['id']));
            }
            $first = $byId[$after];
            if ($first['type'] !== 'script' && !in_array($first['trigger']['type'], ['dom_ready', 'window_load'], true)) {
                throw new \InvalidArgumentException(sprintf('Tag %s runs after %s, which can run many times. Choose a tag that runs once per page: a script, or an action triggered on Document ready or Window finished loading.', $tag['id'], $after));
            }
            $chain = [$tag['id']];
            for ($current = $after; $current !== null; $current = $byId[$current]['after'] ?? null) {
                $position = array_search($current, $chain, true);
                if ($position !== false) {
                    $loop = array_slice($chain, $position);
                    throw new \InvalidArgumentException(sprintf('Tags %s wait for each other, so none of them would run. Remove the tag chaining from one of them.', count($loop) === 2 ? implode(' and ', $loop) : implode(', ', $loop)));
                }
                $chain[] = $current;
            }
        }

        return $tags;
    }

    /** @return array{type: string, event?: string} */
    private static function validateTrigger(mixed $trigger): array
    {
        if (!is_array($trigger) || array_diff(array_keys($trigger), ['type', 'event']) !== []) {
            throw new \InvalidArgumentException('A tag trigger must contain type and, for an event trigger, event.');
        }
        $type = array_key_exists('type', $trigger) ? $trigger['type'] : 'dom_ready';
        if (!in_array($type, ['dom_ready', 'window_load', 'document_event', 'window_event', 'data_layer'], true)) {
            throw new \InvalidArgumentException('A tag trigger type must be dom_ready, window_load, document_event, window_event or data_layer.');
        }
        if (in_array($type, ['dom_ready', 'window_load'], true)) {
            if (array_key_exists('event', $trigger)) {
                throw new \InvalidArgumentException('Document-ready and window-load triggers do not use an event name.');
            }

            return ['type' => $type];
        }
        $event = $trigger['event'] ?? null;
        if (!is_string($event) || preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/D', $event) !== 1) {
            throw new \InvalidArgumentException('An event trigger needs a name of 1–100 letters, digits, underscores, dots, colons or hyphens, beginning with a letter.');
        }

        return ['type' => $type, 'event' => $event];
    }

}
