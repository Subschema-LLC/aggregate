<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Event;
use App\Service\CustomDataSettings;
use App\Service\EventRowWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;

/**
 * Writes the same events through Doctrine ORM and through EventRowWriter and
 * compares the stored rows column by column. EventRowWriterTest runs it on
 * SQLite; the fresh-install CI job runs it on every supported engine.
 */
final class EventRowParity
{
    /**
     * Events built the way AnonymousEventRecorder and TrackEventHandler build
     * them, including values the entity must clean or clear.
     *
     * @return array<string, \Closure(): Event>
     */
    public static function examples(): array
    {
        $at = new \DateTimeImmutable('2026-11-10 14:37:12.345678', new \DateTimeZone('UTC'));

        return [
            'anonymous view with properties, geography and the traffic flag' => static fn (): Event => (new Event())
                ->setWebsiteToken('parity-site')
                ->setPrivacyMode('anonymous')
                ->setEventName('view')
                ->setUrl('/pricing')
                ->setReferrer('search')
                ->setDeviceClass('desktop')
                ->setViewportBucket('large')
                ->setGeoArea('country:US')
                // The entity clears identifiers on anonymous rows.
                ->setVisitorId('must-not-be-stored')
                ->setScreenWidth(1440)
                // The entity drops the out-of-range sequence and the unknown channel.
                ->setApprovedAnonymousCustomData([
                    'plan' => 'Prò',
                    CustomDataSettings::PAGE_SEQUENCE_PROPERTY => 25,
                    CustomDataSettings::ENTRY_CHANNEL_PROPERTY => 'not-a-channel',
                ])
                ->setInternalTraffic(true)
                ->setCreatedAt($at),
            'anonymous goal without properties' => static fn (): Event => (new Event())
                ->setWebsiteToken('parity-site')
                ->setPrivacyMode('anonymous')
                ->setEventName('purchase.completed')
                ->setUrl('/checkout/done')
                ->setReferrer('direct')
                ->setDeviceClass('mobile')
                ->setViewportBucket('small')
                ->setGoalEvent('purchase')
                ->setApprovedAnonymousCustomData(null)
                ->setCreatedAt($at),
            'enhanced event with identifiers and typed properties' => static fn (): Event => (new Event())
                ->setWebsiteToken('parity-site')
                ->setPrivacyMode('enhanced')
                ->setEventName('purchase.completed')
                ->setUrl('/checkout?step=2')
                ->setReferrer('https://search.example/?q=shoes')
                ->setDeviceClass('desktop')
                ->setViewportBucket('large')
                ->setGeoArea('continent:EU')
                ->setGeneralizedUserAgent('Chrome / desktop')
                ->setScreenWidth(1440)
                ->setVisitorId('visitor_abc')
                ->setSessionId('session_abc')
                ->setConsentState('granted')
                ->setGoalEvent('purchase')
                ->setCustomData(['plan' => 'Prò', 'total' => 12.5, 'items' => 3, 'gift' => false, 'note' => null])
                ->setInternalTraffic(false)
                ->setCreatedAt($at),
            'enhanced event with nothing optional' => static fn (): Event => (new Event())
                ->setWebsiteToken('parity-site')
                ->setPrivacyMode('enhanced')
                ->setEventName('view')
                ->setUrl('/')
                ->setReferrer(null)
                ->setConsentState('granted')
                ->setCustomData(null)
                ->setCreatedAt($at),
        ];
    }

    /** An entity manager configured like config/packages/doctrine.yaml, for the comparison only. */
    public static function entityManager(Connection $connection, string $entityDirectory): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfig([$entityDirectory], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER, true));
        $config->setIdentityGenerationPreferences([PostgreSQLPlatform::class => ClassMetadata::GENERATOR_TYPE_IDENTITY]);
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('AggregateParityProxies');
        }

        return new EntityManager($connection, $config);
    }

    /**
     * Writes every example both ways, compares the rows and deletes them again.
     *
     * @return list<string> one line per difference; empty when the rows match
     */
    public static function differences(Connection $connection, EntityManager $manager): array
    {
        $writer = new EventRowWriter($connection);
        $differences = [];
        foreach (self::examples() as $name => $build) {
            $before = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM events');
            $manager->persist($build());
            $manager->flush();
            $manager->clear();
            $writer->insert($build());
            $rows = $connection->fetchAllAssociative(
                'SELECT * FROM events WHERE id > ? ORDER BY id',
                [$before],
                [ParameterType::INTEGER],
            );
            $connection->executeStatement('DELETE FROM events WHERE id > ?', [$before], [ParameterType::INTEGER]);
            if (count($rows) !== 2) {
                $differences[] = sprintf('%s: expected 2 rows, found %d.', $name, count($rows));
                continue;
            }
            [$orm, $direct] = array_map(self::normalize(...), $rows);
            foreach (array_keys($orm + $direct) as $column) {
                if (($orm[$column] ?? null) !== ($direct[$column] ?? null)) {
                    $differences[] = sprintf(
                        '%s: %s is %s through the ORM but %s through the writer.',
                        $name,
                        $column,
                        var_export($orm[$column] ?? null, true),
                        var_export($direct[$column] ?? null, true),
                    );
                }
            }
        }

        return $differences;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string|null>
     */
    private static function normalize(array $row): array
    {
        $normalized = [];
        foreach ($row as $column => $value) {
            $column = strtolower((string) $column);
            if ($column === 'id') {
                continue;
            }
            $normalized[$column] = $value === null ? null : (string) $value;
        }

        return $normalized;
    }
}
