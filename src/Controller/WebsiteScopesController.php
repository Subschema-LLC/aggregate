<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/** Named website groups that share collection settings and data models. */
final class WebsiteScopesController extends AbstractController
{
    use DashboardAccess;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly WebsiteScopeCatalog $catalog,
        private readonly WebsiteConfigManager $websites,
    ) {
    }

    #[Route('/dashboard/website-groups', name: 'app_website_groups', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $label = $this->catalog->hasLoadError()
            ? ['singular' => WebsiteScopeCatalog::DEFAULT_SINGULAR, 'plural' => WebsiteScopeCatalog::DEFAULT_PLURAL]
            : $this->catalog->label();
        $groups = $this->catalog->hasLoadError() ? [] : $this->catalog->groups();
        $websites = [];
        foreach ($this->websites->getWebsites() as $site) {
            $token = (string) ($site['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $groupId = $this->catalog->hasLoadError() ? null : $this->catalog->groupIdForToken($token);
            $websites[] = [
                'token' => $token,
                'name' => (string) ($site['name'] ?? 'Unnamed'),
                'domain' => (string) ($site['domain'] ?? ''),
                'group_id' => $groupId,
            ];
        }

        return $this->renderDashboardPage('website_scopes/index.html.twig', [
            'label' => $label,
            'groups' => $groups,
            'websites' => $websites,
            'catalog_error' => $this->catalog->hasLoadError(),
        ]);
    }

    #[Route('/dashboard/website-groups/label', name: 'app_website_groups_label', methods: ['POST'])]
    public function saveLabel(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        if (!$this->isCsrfTokenValid('website_scopes', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_website_groups');
        }
        try {
            $this->catalog->setLabel(
                (string) $request->request->get('singular', ''),
                (string) $request->request->get('plural', ''),
            );
            $this->addFlash('success', 'The name for website groups was saved.');
        } catch (\Throwable $error) {
            $this->addFlash('error', $error->getMessage());
        }

        return $this->redirectToRoute('app_website_groups');
    }

    #[Route('/dashboard/website-groups/create', name: 'app_website_groups_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        if (!$this->isCsrfTokenValid('website_scopes', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_website_groups');
        }
        try {
            $id = $this->catalog->createGroup((string) $request->request->get('name', ''));
            $this->addFlash('success', 'Created. Its shared settings can be edited from Collection controls or the data model.');
            $this->addFlash('info', 'Group id: '.$id);
        } catch (\Throwable $error) {
            $this->addFlash('error', $error->getMessage());
        }

        return $this->redirectToRoute('app_website_groups');
    }

    #[Route('/dashboard/website-groups/{id}/rename', name: 'app_website_groups_rename', methods: ['POST'], requirements: ['id' => '[a-z0-9][a-z0-9-]{0,62}'])]
    public function rename(string $id, Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        if (!$this->isCsrfTokenValid('website_scopes', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_website_groups');
        }
        try {
            $this->catalog->renameGroup($id, (string) $request->request->get('name', ''));
            $this->addFlash('success', 'Name saved. The id used in files stays '.$id.'.');
        } catch (\Throwable $error) {
            $this->addFlash('error', $error->getMessage());
        }

        return $this->redirectToRoute('app_website_groups');
    }

    #[Route('/dashboard/website-groups/{id}/delete', name: 'app_website_groups_delete', methods: ['POST'], requirements: ['id' => '[a-z0-9][a-z0-9-]{0,62}'])]
    public function delete(string $id, Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        if (!$this->isCsrfTokenValid('website_scopes', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_website_groups');
        }
        try {
            $this->catalog->deleteGroup($id);
            $this->addFlash('success', 'Removed. Its websites now use the deployment settings unless they have their own.');
        } catch (\Throwable $error) {
            $this->addFlash('error', $error->getMessage());
        }

        return $this->redirectToRoute('app_website_groups');
    }

    #[Route('/dashboard/website-groups/assign', name: 'app_website_groups_assign', methods: ['POST'])]
    public function assign(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        if (!$this->isCsrfTokenValid('website_scopes', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_website_groups');
        }
        $assignments = $request->request->all('assignment');
        if (!is_array($assignments)) {
            $this->addFlash('error', 'Choose a group for each website. Nothing was saved.');

            return $this->redirectToRoute('app_website_groups');
        }
        try {
            foreach ($assignments as $token => $groupId) {
                if (!is_string($token) || !is_string($groupId)) {
                    throw new \InvalidArgumentException('Choose a group for each website. Nothing was saved.');
                }
                $this->catalog->assign($token, $groupId === '' ? null : $groupId);
            }
            $this->addFlash('success', 'Website membership saved.');
        } catch (\Throwable $error) {
            $this->addFlash('error', $error->getMessage());
        }

        return $this->redirectToRoute('app_website_groups');
    }
}
