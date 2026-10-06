<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\EventRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class WebsiteActivityService
{
    public const DEFAULT_ACTIVE_DAYS = 1;
    public const DEFAULT_STALE_DAYS = 3;
    public const KEY_ACTIVE_DAYS = "website_activity_active_days";
    public const KEY_STALE_DAYS = "website_activity_stale_days";
    public const CACHE_KEY = "aggregate.website_activity_status";
    public const CACHE_TTL_SECONDS = 900; // 15 minutes

    public const STATUS_ACTIVE = "active";
    public const STATUS_IDLE = "idle";
    public const STATUS_INACTIVE = "inactive";
    public const STATUS_NONE = "none";

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly WebsiteConfigManager $websiteManager,
        private readonly AggregateConfigLoader $config,
        private readonly CacheInterface $cache,
    ) {
    }

    public function getActiveDays(): int
    {
        $val = (int) $this->config->getWithEnvFallback(self::KEY_ACTIVE_DAYS, self::DEFAULT_ACTIVE_DAYS);
        return max(1, min(365, $val));
    }

    public function getStaleDays(): int
    {
        $active = $this->getActiveDays();
        $val = (int) $this->config->getWithEnvFallback(self::KEY_STALE_DAYS, self::DEFAULT_STALE_DAYS);
        return max($active, min(365, $val));
    }

    /**
     * @return array<string, array{
     *     token: string,
     *     status: string,
     *     label: string,
     *     relative_time: string,
     *     last_event_at: ?string,
     *     last_event_at_formatted: ?string,
     *     active_days: int,
     *     stale_days: int
     * }>
     */
    public function getStatuses(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            $this->cache->delete(self::CACHE_KEY);
        }

        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            return $this->computeStatuses();
        });
    }

    /**
     * Refresh the cached statuses immediately.
     *
     * @return array<string, array<string, mixed>>
     */
    public function refresh(): array
    {
        return $this->getStatuses(forceRefresh: true);
    }

    /**
     * @return array{
     *     token: string,
     *     status: string,
     *     label: string,
     *     relative_time: string,
     *     last_event_at: ?string,
     *     last_event_at_formatted: ?string,
     *     active_days: int,
     *     stale_days: int
     * }|null
     */
    public function getStatusForToken(string $token): ?array
    {
        if ($token === "") {
            return null;
        }

        $statuses = $this->getStatuses();

        return $statuses[$token] ?? [
            "token" => $token,
            "status" => self::STATUS_NONE,
            "label" => "Waiting for setup",
            "relative_time" => "Never",
            "last_event_at" => null,
            "last_event_at_formatted" => null,
            "active_days" => $this->getActiveDays(),
            "stale_days" => $this->getStaleDays(),
        ];
    }

    /**
     * Compute activity statuses directly without cache.
     *
     * @return array<string, array<string, mixed>>
     */
    public function computeStatuses(): array
    {
        $websites = $this->websiteManager->getWebsites();
        $tokens = [];
        foreach ($websites as $site) {
            $token = (string) ($site["token"] ?? "");
            if ($token !== "") {
                $tokens[] = $token;
            }
        }

        $activeDays = $this->getActiveDays();
        $staleDays = $this->getStaleDays();

        $lastDates = $this->eventRepository->findLastEventDatesByTokens($tokens);
        $now = new \DateTimeImmutable("now", new \DateTimeZone("UTC"));

        $statuses = [];
        foreach ($tokens as $token) {
            $lastEventAt = $lastDates[$token] ?? null;

            if ($lastEventAt === null) {
                $status = self::STATUS_NONE;
                $label = "Waiting for setup";
                $relativeTime = "Never";
            } else {
                $diffSeconds = $now->getTimestamp() - $lastEventAt->getTimestamp();
                $diffDays = $diffSeconds / 86400;

                if ($diffDays <= $activeDays) {
                    $status = self::STATUS_ACTIVE;
                    $label = "Receiving data";
                } elseif ($diffDays <= $staleDays) {
                    $status = self::STATUS_IDLE;
                    $label = "Idle";
                } else {
                    $status = self::STATUS_INACTIVE;
                    $label = "No recent data";
                }

                $relativeTime = $this->formatRelativeTime($lastEventAt, $now);
            }

            $statuses[$token] = [
                "token" => $token,
                "status" => $status,
                "label" => $label,
                "relative_time" => $relativeTime,
                "last_event_at" => $lastEventAt?->format(\DateTimeInterface::ATOM),
                "last_event_at_formatted" => $lastEventAt ? $lastEventAt->format("Y-m-d H:i") . " UTC" : null,
                "active_days" => $activeDays,
                "stale_days" => $staleDays,
            ];
        }

        return $statuses;
    }

    public function formatRelativeTime(\DateTimeInterface $dateTime, ?\DateTimeInterface $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable("now", new \DateTimeZone("UTC"));
        $diffSeconds = max(0, $now->getTimestamp() - $dateTime->getTimestamp());

        if ($diffSeconds < 60) {
            return "just now";
        }
        if ($diffSeconds < 3600) {
            $mins = (int) floor($diffSeconds / 60);
            return $mins . "m ago";
        }
        if ($diffSeconds < 86400) {
            $hours = (int) floor($diffSeconds / 3600);
            return $hours . "h ago";
        }
        $days = (int) floor($diffSeconds / 86400);
        if ($days < 30) {
            return $days . "d ago";
        }

        return $dateTime->format("M j, Y");
    }
}
