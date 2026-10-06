<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\WebsiteActivityLoginSubscriber;
use App\Service\WebsiteActivityService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class WebsiteActivityLoginSubscriberTest extends TestCase
{
    public function testSubscribedEvents(): void
    {
        $events = WebsiteActivityLoginSubscriber::getSubscribedEvents();
        self::assertArrayHasKey(LoginSuccessEvent::class, $events);
        self::assertSame('onLoginSuccess', $events[LoginSuccessEvent::class]);
    }

    public function testOnLoginSuccessRefreshesActivity(): void
    {
        $activityService = $this->createMock(WebsiteActivityService::class);
        $activityService->expects(self::once())->method('refresh')->willReturn([]);

        $subscriber = new WebsiteActivityLoginSubscriber($activityService);

        $event = new LoginSuccessEvent(
            $this->createMock(\Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge('admin', static fn () => null)),
            new NullToken(),
            new Request(),
            new Response(),
            'main'
        );

        $subscriber->onLoginSuccess($event);
    }
}
