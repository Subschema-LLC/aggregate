<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\Access\DashboardAccessVoter;
use App\Security\Access\DashboardPages;
use App\Security\Access\UserAccessResolver;
use App\Service\AggregateConfigLoader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Enforces DashboardPages on every dashboard request before its controller
 * runs, so a page cannot be reached by a standard user just because its
 * controller forgot a check. A route DashboardPages does not list is
 * administrator-only.
 *
 * A refused request gets a 403, with two friendlier exceptions for signed-in
 * users: a view-only user's save is sent back to the page with a message, and
 * the dashboard home (where sign-in lands) opens the first page the user can
 * use, or their profile.
 *
 * Runs after AdminAuditSubscriber starts recording, so refused changes are in
 * the audit trail as denied.
 */
final class DashboardAccessSubscriber implements EventSubscriberInterface
{
    private const DASHBOARD_PATH = '#^/dashboard(?:/|$)#';

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly TokenStorageInterface $tokens,
        private readonly UserAccessResolver $resolver,
        private readonly UrlGeneratorInterface $urls,
        private readonly AggregateConfigLoader $config,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', -16]];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (preg_match(self::DASHBOARD_PATH, $request->getPathInfo()) !== 1 || !$this->config->isDashboardEnabled()) {
            // A disabled dashboard answers not found from its controllers.
            return;
        }
        if ($this->authorization->isGranted(DashboardAccessVoter::ROUTE, $request)) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        $response = $user instanceof User ? $this->friendlyRefusal($request, $user) : null;
        if ($response === null) {
            throw new AccessDeniedException('You do not have access to this page. Ask an administrator if you need it.');
        }
        $event->setController(static fn (): Response => $response);
    }

    private function friendlyRefusal(Request $request, User $user): ?Response
    {
        $route = (string) $request->attributes->get('_route', '');
        $access = $this->resolver->forUser($user);

        if ($route === 'app_dashboard' && $request->isMethodSafe()) {
            foreach (DashboardPages::PAGES as $page => $definition) {
                $main = DashboardPages::mainRoute($page);
                if ($main !== null && $main !== $route && DashboardAccessVoter::satisfies($access, DashboardPages::requirement($main))) {
                    return $this->redirect($main);
                }
            }

            return $this->redirect('app_profile');
        }

        $requirement = DashboardPages::requirement($route, $request->getMethod());
        $page = $requirement['page'];
        if ($page === null || $requirement['level'] !== DashboardPages::EDIT || $request->isMethodSafe()
            || $request->isXmlHttpRequest() || !$access->allows($page, DashboardPages::VIEW)) {
            return null;
        }
        $message = $access->allows($page, DashboardPages::EDIT)
            ? sprintf('That change on %s needs access to every website. Ask an administrator if you need it.', DashboardPages::label($page))
            : sprintf('You have view-only access to %s. Ask an administrator for edit access.', DashboardPages::label($page));
        if ($request->hasSession() && ($session = $request->getSession()) instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $message);
        }
        $request->attributes->set(AdminAuditSubscriber::REFUSED, true);

        return $this->back($request, $page);
    }

    private function back(Request $request, string $page): Response
    {
        $referer = (string) $request->headers->get('referer', '');
        $parts = parse_url($referer);
        if (is_array($parts) && ($parts['host'] ?? null) === $request->getHost() && str_starts_with($parts['path'] ?? '', '/dashboard')) {
            return $this->private(new RedirectResponse($referer, Response::HTTP_SEE_OTHER));
        }
        $main = DashboardPages::mainRoute($page);

        return $main !== null ? $this->redirect($main) : $this->redirect('app_profile');
    }

    private function redirect(string $route): Response
    {
        return $this->private(new RedirectResponse($this->urls->generate($route), Response::HTTP_SEE_OTHER));
    }

    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
