<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Writes one collected event with a single DBAL insert instead of Doctrine
 * ORM's persist() and flush().
 *
 * The Event entity stays the one place that checks a row: callers build it
 * with the entity's setters, which refuse or clean values, and insert() runs
 * the same enforcePrivacyInvariants() that the ORM calls before saving. Only
 * the ORM's unit of work is skipped, along with the read-back of the new ID,
 * which nothing on the collection path uses. See "Event rows written with one
 * insert, checked by the entity" in docs/DESIGN-DECISIONS.md.
 *
 * Not final: ingestion and message-handler tests replace it with a double.
 */
class EventRowWriter
{
    public const TABLE = 'events';

    /**
     * Every mapped column except the generated id, with its Doctrine type.
     * EventRowWriterTest fails when this differs from the entity's mapping.
     */
    public const COLUMN_TYPES = [
        'website_token' => Types::STRING,
        'event_name' => Types::STRING,
        'url' => Types::TEXT,
        'referrer' => Types::TEXT,
        'generalized_user_agent' => Types::STRING,
        'privacy_mode' => Types::STRING,
        'device_class' => Types::STRING,
        'viewport_bucket' => Types::STRING,
        'geo_area' => Types::STRING,
        'screen_width' => Types::INTEGER,
        'visitor_id' => Types::STRING,
        'session_id' => Types::STRING,
        'consent_state' => Types::STRING,
        'custom_data' => Types::JSON,
        'goal_event' => Types::STRING,
        'created_at' => Types::DATETIME_IMMUTABLE,
        'archived_at' => Types::DATETIME_IMMUTABLE,
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function insert(Event $event): void
    {
        $event->enforcePrivacyInvariants();
        $this->connection->insert(self::TABLE, self::row($event), self::COLUMN_TYPES);
    }

    /**
     * The values insert() writes, keyed like COLUMN_TYPES. Call it only on an
     * event whose invariants have been enforced.
     *
     * @return array<string, mixed>
     */
    public static function row(Event $event): array
    {
        return [
            'website_token' => $event->getWebsiteToken(),
            'event_name' => $event->getEventName(),
            'url' => $event->getUrl(),
            'referrer' => $event->getReferrer(),
            'generalized_user_agent' => $event->getGeneralizedUserAgent(),
            'privacy_mode' => $event->getPrivacyMode(),
            'device_class' => $event->getDeviceClass(),
            'viewport_bucket' => $event->getViewportBucket(),
            'geo_area' => $event->getGeoArea(),
            'screen_width' => $event->getScreenWidth(),
            'visitor_id' => $event->getVisitorId(),
            'session_id' => $event->getSessionId(),
            'consent_state' => $event->getConsentState(),
            'custom_data' => $event->getCustomData(),
            'goal_event' => $event->getGoalEvent(),
            'created_at' => $event->getCreatedAt(),
            'archived_at' => $event->getArchivedAt(),
        ];
    }
}
