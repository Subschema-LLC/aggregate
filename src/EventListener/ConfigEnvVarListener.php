<?php

namespace App\EventListener;

use App\Service\AggregateConfigLoader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Loads config from aggregate.yaml and makes it available as environment variables
 * for Doctrine, Messenger, and other Symfony components that expect env vars.
 */
class ConfigEnvVarListener implements EventSubscriberInterface
{
    private static bool $loaded = false;

    public function __construct(
        private readonly AggregateConfigLoader $config
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Run early, before most other listeners
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

        // Export config values as environment variables if not already set
        $this->setEnvIfNotExists('DATABASE_URL', $this->config->get('database_url'));
        $this->setEnvIfNotExists('MESSENGER_TRANSPORT_DSN', $this->config->get('messenger_transport_dsn'));
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

        // Only set if not already defined
        if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
            $_ENV[$key] = (string) $value;
            $_SERVER[$key] = (string) $value;
            putenv("$key=" . (string) $value);
        }
    }
}
