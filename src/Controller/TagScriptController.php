<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BrowserScriptCache;
use App\Service\BrowserScriptCompactor;
use App\Service\SiteScriptConfig;
use App\Service\TagManagerScript;
use App\Service\TagManagerScriptUnavailable;
use App\Service\TagManagerSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class TagScriptController
{
    public const PLACEHOLDER = TagManagerScript::PLACEHOLDER;
    public const SOURCE_DEFAULTS = TagManagerScript::SOURCE_DEFAULTS;
    public const CUSTOM_PLACEHOLDER = TagManagerScript::CUSTOM_PLACEHOLDER;
    public const CUSTOM_DEFAULTS = TagManagerScript::CUSTOM_DEFAULTS;

    private readonly TagManagerScript $script;

    public function __construct(
        TagManagerSettings $settings,
        string $projectDir = __DIR__.'/../..',
        private readonly ?SiteScriptConfig $sites = null,
        ?BrowserScriptCompactor $compactor = null,
    ) {
        $this->script = new TagManagerScript($settings, $projectDir, $compactor);
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
            $script = $this->script->render($siteId, $request?->query->get('min') === '1');
        } catch (TagManagerScriptUnavailable $unavailable) {
            return $this->response('/* '.$unavailable->getMessage().' */', 503);
        }
        $response = $this->response($script['content']);
        $response->headers->set('X-Aggregate-Script', $script['variant']);

        // Reused for five minutes, then confirmed unchanged with a 304.
        return BrowserScriptCache::apply($response, $request);
    }

    private function response(string $content, int $status = 200): Response
    {
        return new Response($content, $status, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
