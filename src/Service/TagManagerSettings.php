<?php

declare(strict_types=1);

namespace App\Service;

/** Website script settings, with a legacy deployment-wide configuration. */
final class TagManagerSettings
{
    public const MAX_TAGS = 20;
    public const DEFAULTS = ['enabled' => false, 'variables' => [], 'tags' => []];

    public function __construct(private readonly AggregateConfigLoader $config, private readonly ?SiteScriptConfig $sites = null)
    {
    }

    /** @return array{enabled: bool, tags: list<array>} */
    public function all(?string $siteId = null): array
    {
        $config = $this->configuration($siteId);
        $config->assertHealthy();
        $values = $config->all();

        return self::validate(array_key_exists('tag_manager', $values) ? $values['tag_manager'] : self::DEFAULTS);
    }

    public function save(array $submitted, ?string $siteId = null): void
    {
        $validated = self::validate($submitted);
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

    /** Only enabled, explicitly public script definitions reach the browser. */
    public function toBrowserConfig(?string $siteId = null): array
    {
        $settings = $this->all($siteId);

        return [
            'enabled' => $settings['enabled'],
            'variables' => $settings['enabled'] ? $settings['variables'] : [],
            'tags' => $settings['enabled'] ? array_values(array_map(
                static fn (array $tag): array => array_diff_key($tag, ['enabled' => true]),
                array_filter($settings['tags'], static fn (array $tag): bool => $tag['enabled']),
            )) : [],
        ];
    }

    /** @return list<string> Categories needed by the tracker and enabled tags. */
    public function consentCategories(?string $siteId = null): array
    {
        $settings = $this->all($siteId);
        $categories = ['analytics' => true];
        if ($settings['enabled']) {
            foreach ($settings['tags'] as $tag) {
                if ($tag['enabled'] && $tag['consent'] !== 'none') {
                    $categories[$tag['consent']] = true;
                }
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
    public static function validate(mixed $submitted): array
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
        foreach ($tags as $tag) {
            if (!is_array($tag) || array_diff(array_keys($tag), ['id', 'type', 'src', 'method', 'args', 'enabled', 'consent', 'trigger']) !== []) {
                throw new \InvalidArgumentException('Each tag must contain only its ID, action, enabled setting, consent and trigger. Inline code is not supported.');
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
                if (array_key_exists('method', $tag) || array_key_exists('args', $tag)) {
                    throw new \InvalidArgumentException('A script tag uses src; method and args belong to a call tag.');
                }
                $src = TagManagerVariables::validateSource($tag['src'] ?? null, $variables);
                if (isset($sources[$src])) {
                    throw new \InvalidArgumentException('Each script tag needs a unique HTTPS URL template.');
                }
                $sources[$src] = true;
                $action = ['src' => $src];
            } elseif ($type === 'call') {
                if (array_key_exists('src', $tag)) {
                    throw new \InvalidArgumentException('A call tag uses method and args; src belongs to a script tag.');
                }
                $action = [
                    'method' => TagManagerValues::validateMethod($tag['method'] ?? null),
                    'args' => TagManagerValues::validateArguments(array_key_exists('args', $tag) ? $tag['args'] : [], $variables),
                ];
            } else {
                throw new \InvalidArgumentException('A tag type must be script or call.');
            }
            $ids[$id] = true;
            $validated[] = ['id' => $id, ...$action, 'enabled' => $tagEnabled, 'consent' => $consent, 'trigger' => $trigger, 'type' => $type];
        }

        return ['enabled' => $enabled, 'variables' => $variables, 'tags' => $validated];
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
