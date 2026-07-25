<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Read-only ORM mapping for the DBAL-managed singleton used by the BI views.
 * This keeps Doctrine schema tooling aware that the table belongs to the app.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'analytics_privacy_settings')]
final class AnalyticsPrivacySetting
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(name: 'anonymous_min_cell_count', type: 'integer', options: ['default' => 5])]
    private int $anonymousMinCellCount;

    #[ORM\Column(name: 'anonymous_geo_min_cell_count', type: 'integer', options: ['default' => 25])]
    private int $anonymousGeoMinCellCount;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;
}
