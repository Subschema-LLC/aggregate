<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\BiPageGroups;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Page groups for bi_anonymous_daily_v1; YAML and app:analytics:bi-views:regenerate are the headless path. */
final class BiPageGroupsController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'bi_page_groups';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly BiPageGroups $pageGroups,
        private readonly AnalyticsPrivacySettings $thresholds,
        private readonly AnonymousBiViewManager $views,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/data-model/page-groups', name: 'app_bi_page_groups', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        try {
            $text = BiPageGroups::toLines($this->pageGroups->rules());
            $error = null;
        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to load page groups.', ['exception' => $e]);
            $text = '';
            $error = $e->getMessage();
        }

        return $this->page($text, $error);
    }

    #[Route('/dashboard/data-model/page-groups/save', name: 'app_bi_page_groups_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $form = $request->request->all();
        $token = $form['_csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_bi_page_groups');
        }

        $text = is_string($form['page_groups'] ?? null) ? $form['page_groups'] : '';
        try {
            $rules = BiPageGroups::parseLines($text);
        } catch (\InvalidArgumentException $e) {
            return $this->page($text, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->pageGroups->save($rules);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save page groups.', ['exception' => $e]);
            $this->addFlash('error', 'The page groups could not be saved. Check the active YAML configuration and file permissions.');

            return $this->redirectToRoute('app_bi_page_groups');
        }

        try {
            $thresholds = $this->thresholds->getMinimumCellCounts();
            $this->views->regenerate($thresholds['anonymous'], $thresholds['geo']);
            $this->addFlash('success', sprintf('Saved %d page group %s and regenerated the BI views.', count($rules), count($rules) === 1 ? 'rule' : 'rules'));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to regenerate BI views after saving page groups.', ['exception' => $e]);
            $this->addFlash('warning', 'The page groups were saved, but the BI views were not regenerated. Run php bin/console app:analytics:bi-views:regenerate after correcting the problem.');
        }

        return $this->redirectToRoute('app_bi_page_groups');
    }

    private function page(string $text, ?string $error, int $status = Response::HTTP_OK): Response
    {
        return $this->render('settings/page_groups.html.twig', [
            'page_groups' => $text,
            'page_groups_error' => $error,
            'max_rules' => BiPageGroups::MAX_RULES,
        ], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store, max-age=0']));
    }

    private function denyUnlessAvailableToAdmin(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }
}
