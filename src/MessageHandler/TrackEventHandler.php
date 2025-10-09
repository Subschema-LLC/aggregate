<?php

namespace App\MessageHandler;

use App\Entity\Event;
use App\Entity\PageView;
use App\Repository\WebsiteRepository;
use App\Message\TrackEventMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class TrackEventHandler
{
    public function __construct(
        private readonly WebsiteRepository $websites,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(TrackEventMessage $msg): void
    {
        $website = $this->websites->findOneByPublicToken($msg->websiteToken);
        if (!$website) {
            // Silently drop invalid website to keep worker resilient
            return;
        }

        $hash = $this->hashDailyIp($msg->ip);
        $ua = $this->generalizeUserAgent($msg->userAgent);

        // Always record a page view (default mode)
        $pv = (new PageView())
            ->setWebsite($website)
            ->setUrl($msg->url)
            ->setReferrer($msg->referrer)
            ->setDailyIpHash($hash)
            ->setGeneralizedUserAgent($ua)
            ->setScreenWidth($msg->screenWidth);
        $this->em->persist($pv);

        if ($msg->eventName) {
            $ev = (new Event())
                ->setWebsite($website)
                ->setPageView($pv)
                ->setEventName($msg->eventName)
                ->setCustomData($this->sanitizeEventData($msg->eventData));
            $this->em->persist($ev);
        }

        $this->em->flush();
    }

    private function hashDailyIp(string $ip): string
    {
        $salt = $_ENV['DAILY_SALT_SECRET'] ?? 'dev-salt';
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
        // Limit depth/size to prevent abuse
        $clean = [];
        foreach ($data as $k => $v) {
            if (!is_string($k)) { continue; }
            if (is_scalar($v) || $v === null) {
                $val = is_string($v) ? substr($v, 0, 500) : $v;
                $clean[substr($k, 0, 100)] = $val;
            }
        }
        return $clean ?: null;
    }
}
