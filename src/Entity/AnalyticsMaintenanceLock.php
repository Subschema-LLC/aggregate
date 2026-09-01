<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Read-only mapping for the DBAL-managed singleton maintenance lease. */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(
    name: 'analytics_maintenance_lock',
    options: ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
)]
class AnalyticsMaintenanceLock
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(name: 'owner_token', type: 'string', length: 64, nullable: true)]
    private ?string $ownerToken;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;
}
