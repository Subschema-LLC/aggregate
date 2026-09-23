<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TagManagerSettings;
use App\Service\SiteScriptConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class TagScriptController
{
    public const PLACEHOLDER = '__AGGREGATE_TAG_MANAGER__';
    public const SOURCE_DEFAULTS = 'var tagManagerConfig = {enabled: false, tags: []};';

    public function __construct(
        private readonly TagManagerSettings $settings,
        private readonly string $projectDir = __DIR__.'/../..',
        private readonly ?SiteScriptConfig $sites = null,
    ) {
    }

    #[Route('/lib.js', name: 'aggregate_tag_script', methods: ['GET'])]
    #[Route('/tms-lite/sites/{siteId}/lib.js', name: 'site_tag_script', requirements: ['siteId' => '[a-f0-9]{24}'], methods: ['GET'])]
    public function __invoke(?Request $request = null, ?string $siteId = null): Response
    {
        if ($siteId !== null) {
            try {
                $this->sites?->site($siteId) ?? throw new \InvalidArgumentException();
            } catch (\InvalidArgumentException) {
                return $this->response('/* Website script instance not found. */', 404);
            }
        }
        try {
            $configuration = $this->settings->toBrowserConfig($siteId);
            $json = json_encode($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // No settings, parser messages, or executable tags escape a broken policy.
            return $this->response('/* Tag manager configuration is unavailable. No tags loaded. */', 503);
        }
        $path = $this->projectDir.'/public/tag-manager.js';
        $source = is_readable($path) ? file_get_contents($path) : false;
        if (!is_string($source) || substr_count($source, self::SOURCE_DEFAULTS) !== 1) {
            return $this->response('/* Tag manager source is unavailable. No tags loaded. */', 503);
        }

        $minified = $request?->query->get('min') === '1' ? $this->minifiedTemplate($source) : null;
        $content = $minified !== null
            ? strtr($minified, [self::PLACEHOLDER => $json])
            : str_replace(self::SOURCE_DEFAULTS, 'var tagManagerConfig = '.$json.';', $source);
        $response = $this->response($content);
        $response->headers->set('X-Aggregate-Script', $minified !== null ? 'minified' : 'source');

        return $response;
    }

    private function response(string $content, int $status = 200): Response
    {
        return new Response($content, $status, [
            'Content-Type' => 'application/javascript',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $status === 200 ? 'public, max-age=0, must-revalidate' : 'no-store',
        ]);
    }

    private function minifiedTemplate(string $source): ?string
    {
        $directory = $this->projectDir.'/var/browser';
        $manifestPath = $directory.'/tag-manager-manifest.json';
        $templatePath = $directory.'/tag-manager.template.min.js';
        if (!is_readable($manifestPath) || !is_readable($templatePath)) {
            return null;
        }

        try {
            $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $template = file_get_contents($templatePath);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1 || !is_string($template)
                || ($manifest['sourceSha256'] ?? null) !== hash('sha256', $source)
                || ($manifest['templateSha256'] ?? null) !== hash('sha256', $template)
                || !str_contains($template, self::PLACEHOLDER)) {
                return null;
            }

            return $template;
        } catch (\Throwable) {
            return null;
        }
    }
}
