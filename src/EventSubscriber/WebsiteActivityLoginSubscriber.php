<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\WebsiteActivityService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class WebsiteActivityLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebsiteActivityService $activityService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => "onLoginSuccess",
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        try {
            $this->activityService->refresh();
        } catch (\Throwable) {
            // Never block successful login if activity calculation encounters an issue
        }
    }
}
