<?php

namespace App\EventListener;

use App\Service\AggregateConfigLoader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Loads app-specific config from aggregate.yaml and exposes it as env vars
 * for Symfony components that expect them.
 *
 * DATABASE_URL and MESSENGER_TRANSPORT_DSN are standard Symfony env vars
 * configured in .env files — they are NOT managed here.
 */
class ConfigEnvVarListener implements EventSubscriberInterface
{
    private static bool $loaded = false;

    public function __construct(
        private readonly AggregateConfigLoader $config
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || self::$loaded) {
            return;
        }

        self::$loaded = true;

        $this->setEnvIfNotExists('DAILY_SALT_SECRET', $this->config->get('daily_salt_secret'));
        $this->setEnvIfNotExists('RATE_LIMIT_PER_MINUTE', $this->config->get('rate_limit_per_minute'));
        $this->setEnvIfNotExists('APP_HOST', $this->config->get('app_host'));
        $this->setEnvIfNotExists('JS_NAMESPACE', $this->config->get('js_namespace'));
    }

    private function setEnvIfNotExists(string $key, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
            $_ENV[$key] = (string) $value;
            $_SERVER[$key] = (string) $value;
            putenv("$key=" . (string) $value);
        }
    }
}
