<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Read-only mapping for private, unsuppressed daily geographic archive cells. */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(
    name: 'analytics_archive_geo_events',
    options: ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
)]
#[ORM\Index(name: 'IDX_ARCHIVE_GEO_PRIVACY_DAY', columns: ['privacy_mode', 'event_day'])]
#[ORM\Index(name: 'IDX_ARCHIVE_GEO_RETENTION', columns: ['event_day', 'cell_key'])]
class AnalyticsArchiveGeoEvent
{
    #[ORM\Id]
    #[ORM\Column(name: 'cell_key', type: 'string', length: 64)]
    private string $cellKey;

    #[ORM\Column(name: 'website_token', type: 'string', length: 191)]
    private string $websiteToken;

    #[ORM\Column(name: 'event_day', type: 'date_immutable')]
    private \DateTimeImmutable $eventDay;

    #[ORM\Column(name: 'privacy_mode', type: 'string', length: 20)]
    private string $privacyMode;

    #[ORM\Column(name: 'event_name', type: 'string', length: 191)]
    private string $eventName;

    #[ORM\Column(name: 'geo_area', type: 'string', length: 16)]
    private string $geoArea;

    #[ORM\Column(name: 'event_count', type: 'bigint')]
    private string $eventCount;
}
