<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Read-only mapping for the DBAL-managed BigQuery sync status: one row per
 * synced view, plus the __runner__ row recording the last scheduled run.
 * A running row's owner token works as a per-view lease across replicas.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(
    name: 'analytics_bigquery_sync',
    options: ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
)]
class BigQuerySyncState
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $name;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status;

    #[ORM\Column(name: 'owner_token', type: 'string', length: 64, nullable: true)]
    private ?string $ownerToken;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt;

    #[ORM\Column(name: 'succeeded_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $succeededAt;

    #[ORM\Column(name: 'row_count', type: 'bigint', nullable: true)]
    private ?string $rowCount;

    #[ORM\Column(type: 'string', length: 1000, nullable: true)]
    private ?string $message;

    #[ORM\Column(name: 'job_id', type: 'string', length: 191, nullable: true)]
    private ?string $jobId;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;
}
