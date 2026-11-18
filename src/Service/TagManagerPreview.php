<?php

declare(strict_types=1);

namespace App\Service;

use App\Features\TagManager\PreviewTag\PreviewTag;
use App\Features\TagManager\PreviewTag\TagReferences;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "Test this tag" on the tag manager page: runs one tag, as edited and before
 * it is saved, against a sample event in the administrator's browser.
 */
final class TagManagerPreview
{
    public const PROBE_METHOD = PreviewTag::PROBE_METHOD;
    public const STAND_IN_HOST = PreviewTag::STAND_IN_HOST;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = __DIR__.'/../..',
        private readonly ?PreviewTag $preview = null,
    ) {
    }

    /**
     * @param array{enabled: bool, variables: array<string, string>, tags: list<array>} $settings
     * @return array{
     *     config: array,
     *     custom: list<string>,
     *     probes: array<string, array{alias: string, from: string, path: string}>,
     *     notes: list<string>,
     *     tag: array
     * }
     */
    public function plan(
        array $settings,
        string $tagId,
        string $namespace,
        bool $customScriptsEnabled = true,
    ): array {
        return ($this->preview ?? new PreviewTag())->plan(
            $settings,
            $tagId,
            $namespace,
            $customScriptsEnabled,
        );
    }

    /**
     * public/tag-manager.js with a plan's settings, and with each custom tag
     * replaced by a stand-in that records that it ran.
     *
     * @param list<string> $customIds
     *
     * @throws TagManagerScriptUnavailable when the source cannot be used
     */
    public function script(array $config, array $customIds): string
    {
        $path = $this->projectDir.'/public/tag-manager.js';
        $source = is_readable($path) ? file_get_contents($path) : false;
        if (!is_string($source)
            || substr_count($source, TagManagerScript::SOURCE_DEFAULTS) !== 1
            || substr_count($source, TagManagerScript::CUSTOM_DEFAULTS) !== 1
        ) {
            throw new TagManagerScriptUnavailable('Tag manager source is unavailable.');
        }
        $json = json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
        $standIns = array_map(
            static fn (string $id): string => json_encode(
                $id,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
            ).": function (tag) { 'use strict'; if (typeof self.AggregateTagPreviewCustom === 'function') self.AggregateTagPreviewCustom(tag.id); }",
            $customIds,
        );
        $custom = $standIns === [] ? '{}' : '{'.implode(",\n", $standIns).'}';

        return strtr($source, [
            TagManagerScript::SOURCE_DEFAULTS => 'var tagManagerConfig = '.$json.';',
            TagManagerScript::CUSTOM_DEFAULTS => 'var customScripts = '.$custom.';',
        ]);
    }

    /**
     * @param array<string, mixed> $tag
     * @return list<array{0: string, 1: string}>
     */
    public static function references(array $tag): array
    {
        return TagReferences::references($tag);
    }
}
