<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Read-only mapping for private, unsuppressed hourly archive cells. */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(
    name: 'analytics_archive_events',
    options: ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
)]
#[ORM\Index(name: 'IDX_ARCHIVE_EVENTS_PRIVACY_HOUR', columns: ['privacy_mode', 'event_hour'])]
#[ORM\Index(name: 'IDX_ARCHIVE_EVENTS_RETENTION', columns: ['event_hour', 'cell_key'])]
class AnalyticsArchiveEvent
{
    #[ORM\Id]
    #[ORM\Column(name: 'cell_key', type: 'string', length: 64)]
    private string $cellKey;

    #[ORM\Column(name: 'website_token', type: 'string', length: 191)]
    private string $websiteToken;

    #[ORM\Column(name: 'event_hour', type: 'datetime_immutable')]
    private \DateTimeImmutable $eventHour;

    #[ORM\Column(name: 'privacy_mode', type: 'string', length: 20)]
    private string $privacyMode;

    #[ORM\Column(name: 'event_name', type: 'string', length: 191)]
    private string $eventName;

    #[ORM\Column(name: 'page_path', type: 'text')]
    private string $pagePath;

    #[ORM\Column(name: 'referrer_channel', type: 'text')]
    private string $referrerChannel;

    #[ORM\Column(name: 'device_class', type: 'string', length: 20)]
    private string $deviceClass;

    #[ORM\Column(name: 'viewport_bucket', type: 'string', length: 20)]
    private string $viewportBucket;

    #[ORM\Column(name: 'event_count', type: 'bigint')]
    private string $eventCount;
}
