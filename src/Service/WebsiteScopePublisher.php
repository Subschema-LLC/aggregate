<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Copies agreed reporting columns from website and group models into the
 * deployment model, which is what the private reporting views are built from.
 *
 * Collection already uses the narrower model. Publishing does not change a
 * column that the deployment model already has, and it refuses two scopes that
 * introduce the same new property with different columns.
 */
final class WebsiteScopePublisher
{
    public function __construct(
        private readonly WebsiteScopeCatalog $catalog,
        private readonly CustomDataSettings $settings,
    ) {
    }

    /**
     * @return list<string>
     */
    public function publishModel(): array
    {
        if ($this->catalog->hasLoadError()) {
            throw new \RuntimeException('Website group configuration is invalid. Correct it before publishing columns.');
        }
        $deployment = $this->settings->toArray();
        $properties = $deployment[CustomDataSettings::PROPERTIES_KEY];
        $mappings = $deployment[CustomDataSettings::MAPPINGS_KEY];
        $added = [];
        $introduced = [];
        foreach ($this->catalog->overlayDocuments() as $document) {
            if (!$document['ok'] || ScopeKeys::errors($document['values']) !== null) {
                throw new \RuntimeException('A website or group model could not be read. Correct it before publishing columns.');
            }
            $scopeProperties = $document['values'][CustomDataSettings::PROPERTIES_KEY] ?? null;
            if ($scopeProperties === null) {
                continue;
            }
            if (!is_array($scopeProperties)) {
                throw new \InvalidArgumentException('A website or group model is not valid.');
            }
            foreach ($scopeProperties as $key => $definition) {
                if (!is_string($key) || !is_array($definition)) {
                    throw new \InvalidArgumentException('A website or group model is not valid.');
                }
                if (!isset($properties[$key])) {
                    $properties[$key] = $definition;
                    $introduced[$key] = $document['label'];
                    $added[] = $key;
                    continue;
                }
                if (isset($introduced[$key]) && ($properties[$key]['column'] ?? '') !== ($definition['column'] ?? '')) {
                    throw new \InvalidArgumentException(sprintf('"%s" uses different reporting columns in more than one scope. Agree on one column before publishing.', $key));
                }
            }
            $scopeMappings = $document['values'][CustomDataSettings::MAPPINGS_KEY] ?? null;
            if (!is_array($scopeMappings)) {
                continue;
            }
            foreach ($scopeMappings as $source => $target) {
                if (is_string($source) && is_string($target) && !isset($mappings[$source]) && isset($properties[$target])) {
                    $mappings[$source] = $target;
                }
            }
        }
        if ($added === []) {
            return [];
        }
        $this->settings->save([
            CustomDataSettings::PROPERTIES_KEY => $properties,
            CustomDataSettings::MAPPINGS_KEY => $mappings,
            CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY => $deployment[CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY],
            CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY => $deployment[CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY],
            CustomDataSettings::QUERY_PERSISTENCE_KEY => $deployment[CustomDataSettings::QUERY_PERSISTENCE_KEY],
        ]);

        return $added;
    }
}
