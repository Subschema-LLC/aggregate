<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Read-only mapping for the DBAL-managed audit_trail table: one entry per
 * finished processing task (category "task"), reserved for administrator
 * actions too. Written by App\Service\Operations\AuditTrail.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(
    name: 'audit_trail',
    options: ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
)]
#[ORM\Index(name: 'IDX_AUDIT_TRAIL_OCCURRED', columns: ['occurred_at'])]
class AuditTrailEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private string $id;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'string', length: 32)]
    private string $category;

    #[ORM\Column(type: 'string', length: 64)]
    private string $operation;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $subject;

    #[ORM\Column(type: 'string', length: 16)]
    private string $outcome;

    #[ORM\Column(type: 'string', length: 191, nullable: true)]
    private ?string $actor;

    #[ORM\Column(name: 'processing_task_id', type: 'bigint', nullable: true)]
    private ?string $processingTaskId;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details;
}
