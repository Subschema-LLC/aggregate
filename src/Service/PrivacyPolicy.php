<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\BotDetection\BotDetector;

final class PrivacyPolicy
{
    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    public function hasEnhancedConsent(mixed $consentState): bool
    {
        return is_string($consentState) && strtolower(trim($consentState)) === 'granted';
    }

    public function isAnonymousTrackingEnabled(): bool
    {
        if ($this->config->hasLoadError()) {
            return false;
        }

        return $this->config->getBoolWithEnvFallback('anonymous_tracking_enabled', true);
    }

    /** Whether the deployment-wide strict collection profile is in effect. */
    public function isStrictCollection(): bool
    {
        return (new CollectionProfile($this->config))->isStrict();
    }

    /** What happens to bot events: label (default) or drop. See BotTrafficSettings. */
    public function botTraffic(): string
    {
        return (new BotTrafficSettings($this->config))->mode();
    }

    /**
     * Whether a request comes from a bot: its User-Agent names a crawler,
     * monitor or automated browser or matches bot_user_agents, or the tracker
     * reported an automated browser. A client's report can only add the bot
     * label; it cannot clear a User-Agent match.
     */
    public function isBotRequest(string $userAgent, mixed $submittedDeviceClass): bool
    {
        if (is_string($submittedDeviceClass) && strtolower(trim($submittedDeviceClass)) === 'bot') {
            return true;
        }

        return BotDetector::isBot($userAgent, (new BotTrafficSettings($this->config))->userAgents());
    }

    public function isExcludedPath(string $pagePath): bool
    {
        foreach ($this->excludedPaths() as $pattern) {
            if ($pattern === $pagePath || $this->globMatches($pattern, $pagePath)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function excludedPaths(): array
    {
        $configured = $this->config->getWithEnvFallback('anonymous_excluded_paths', []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }
        if (!is_array($configured)) {
            return [];
        }

        $paths = [];
        foreach ($configured as $path) {
            if (!is_string($path)) {
                continue;
            }

            $path = trim($path);
            if ($path !== '' && str_starts_with($path, '/')) {
                $paths[] = substr($path, 0, 512);
            }
        }

        return $paths;
    }

    private function globMatches(string $pattern, string $path): bool
    {
        // A trailing /** includes the directory itself as well as every depth
        // below it. Elsewhere, ** crosses slashes while * stays in one segment.
        $directoryAndDescendants = str_ends_with($pattern, '/**');
        if ($directoryAndDescendants && $path === substr($pattern, 0, -3)) {
            return true;
        }

        $quoted = preg_quote($pattern, '#');
        $quoted = str_replace(
            ['\*\*', '\*', '\?'],
            ['.*', '[^/]*', '[^/]'],
            $quoted,
        );

        return preg_match('#^'.$quoted.'$#D', $path) === 1;
    }
}
