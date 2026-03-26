<?php

namespace App\MessageHandler;

use App\Entity\Event;
use App\Repository\WebsiteRepository;
use App\Message\TrackEventMessage;
use App\Service\AggregateConfigLoader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class TrackEventHandler
{
    public function __construct(
        private readonly WebsiteRepository $websites,
        private readonly EntityManagerInterface $em,
        private readonly AggregateConfigLoader $config,
    ) {}

    public function __invoke(TrackEventMessage $msg): void
    {
        $website = $this->websites->findOneByPublicToken($msg->websiteToken);
        if (!$website) {
            return;
        }

        $event = (new Event())
            ->setWebsite($website)
            ->setEventName($msg->eventName ?? 'view')
            ->setUrl($msg->url)
            ->setReferrer($msg->referrer)
            ->setDailyIpHash($this->hashDailyIp($msg->ip))
            ->setGeneralizedUserAgent($this->generalizeUserAgent($msg->userAgent))
            ->setScreenWidth($msg->screenWidth)
            ->setSessionId($msg->sessionId)
            ->setCustomData($this->sanitizeEventData($msg->eventData));

        $this->em->persist($event);
        $this->em->flush();
    }

    private function hashDailyIp(string $ip): string
    {
        $salt = $this->config->getWithEnvFallback('daily_salt_secret', 'dev-salt');
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        return hash('sha256', $ip.'|'.$day.'|'.$salt);
    }

    private function generalizeUserAgent(string $ua): string
    {
        $uaLower = strtolower($ua);
        $platform = str_contains($uaLower, 'mobile') || str_contains($uaLower, 'iphone') || str_contains($uaLower, 'android') ? 'Mobile' : 'Desktop';
        $browser = 'Other';
        foreach ([
            'chrome' => 'Chrome',
            'safari' => 'Safari',
            'firefox' => 'Firefox',
            'edge' => 'Edge',
            'opera' => 'Opera',
        ] as $needle => $name) {
            if (str_contains($uaLower, $needle)) { $browser = $name; break; }
        }
        return sprintf('%s on %s', $browser, $platform);
    }

    private function sanitizeEventData(?array $data): ?array
    {
        if ($data === null) { return null; }
        $clean = [];
        foreach ($data as $k => $v) {
            if (!is_string($k)) { continue; }
            if (is_scalar($v) || $v === null) {
                $clean[substr($k, 0, 100)] = is_string($v) ? substr($v, 0, 500) : $v;
            }
        }
        return $clean ?: null;
    }
}
