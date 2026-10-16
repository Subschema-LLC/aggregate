<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Resolves the settings that apply to one website.
 *
 * The deployment configuration is the base. A group file replaces the keys it
 * sets, and a website file replaces those keys again. Environment variables
 * still win, because readers ask the loader for them after this merge. An
 * invalid catalog or overlay fails closed for that website instead of falling
 * back to a broader setting the operator tried to replace.
 */
final class WebsiteScopeResolver
{
    public function __construct(
        private readonly AggregateConfigLoader $deployment,
        private readonly WebsiteScopeCatalog $catalog,
    ) {
    }

    public function forToken(string $token): AggregateConfigLoader
    {
        if (!$this->usableToken($token) || $this->catalog->hasLoadError()) {
            return $this->closed();
        }
        $layers = [];
        $groupId = $this->catalog->groupIdForToken($token);
        if ($groupId !== null && !$this->pushOverlay($layers, 'groups', $groupId)) {
            return $this->closed();
        }
        if (!$this->pushOverlay($layers, 'websites', SiteScriptConfig::idForToken($token))) {
            return $this->closed();
        }

        return $this->view($layers);
    }

    public function forGroup(string $id): AggregateConfigLoader
    {
        if ($this->catalog->hasLoadError() || !$this->catalog->hasGroup($id)) {
            return $this->closed();
        }
        $layers = [];
        if (!$this->pushOverlay($layers, 'groups', $id)) {
            return $this->closed();
        }

        return $this->view($layers);
    }

    /**
     * A script or event that cannot trust its scope collects nothing extra.
     * The invalid flag keeps health and ingestion from treating this as the
     * deployment configuration.
     */
    public function closed(): AggregateConfigLoader
    {
        return $this->deployment->withScope([
            [
                'anonymous_tracking_enabled' => false,
                CollectionProfile::KEY => CollectionProfile::STRICT,
                'anonymous_geo_enabled' => false,
                CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY => false,
            ],
        ], invalid: true);
    }

    /**
     * @param list<array<string, mixed>> $layers
     */
    private function pushOverlay(array &$layers, string $kind, string $id): bool
    {
        $overlay = $this->catalog->readOverlay($kind, $id);
        if (!$overlay['ok'] || ScopeKeys::errors($overlay['values']) !== null) {
            return false;
        }
        if ($overlay['values'] !== []) {
            $layers[] = $overlay['values'];
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $layers
     */
    private function view(array $layers): AggregateConfigLoader
    {
        $view = $this->deployment->withScope($layers);
        if ($view->hasLoadError() && !$this->deployment->hasLoadError()) {
            return $this->closed();
        }
        // No model overlay leaves the deployment model untouched. Ingestion
        // and the tracker already validate that model, so this request does
        // not repeat it. A layer that changes the model is checked as merged,
        // because a page-depth switch can make the deployment model invalid.
        if (!$this->layerChangesModel($layers)) {
            return $view;
        }
        try {
            (new CustomDataSettings($view))->toArray();
        } catch (\Throwable) {
            return $this->closed();
        }

        return $view;
    }

    /**
     * @param list<array<string, mixed>> $layers
     */
    private function layerChangesModel(array $layers): bool
    {
        $modelKeys = array_flip(ScopeKeys::modelKeys());
        foreach ($layers as $layer) {
            if (array_intersect_key($layer, $modelKeys) !== []) {
                return true;
            }
        }

        return false;
    }

    private function usableToken(string $token): bool
    {
        return $token !== '' && strlen($token) <= 191 && preg_match('/\s/', $token) !== 1;
    }
}
