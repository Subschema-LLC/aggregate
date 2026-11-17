<?php

declare(strict_types=1);

namespace App\Tests\Features\DeliverAlert;

use App\Entities\Destination\AlertChannel;
use App\Entities\Incident\AlertFired;
use App\Entities\Incident\AlertIds;
use App\Entities\Incident\AlertRecovered;
use App\Entities\Incident\DeliveryLog;
use App\Features\DeliverAlert\DeliverAlert;
use PHPUnit\Framework\TestCase;

final class DeliverAlertTest extends TestCase
{
    private const SINCE = '2026-11-17T09:00:00Z';
    private const INSTALLATION = 'https://analytics.example.com';

    /** @var list<string> */
    private array $sent = [];
    private bool $channelWorks = true;
    /** @var array<string, string> */
    private array $delivered = [];
    /** @var list<string> */
    private array $records = [];

    public function testSendsToEachDestinationOnceAndCountsIt(): void
    {
        $counts = $this->deliver(new AlertFired('quiet', 'tok', self::SINCE), '2026-11-17T09:00:00Z');
        self::assertSame(['sent' => 2, 'suppressed' => 0, 'failed' => 0], $counts);
        self::assertSame(['hub firing', 'mail firing'], $this->sent);

        $again = $this->deliver(new AlertFired('quiet', 'tok', self::SINCE), '2026-11-17T10:00:00Z');
        self::assertSame(['sent' => 0, 'suppressed' => 2, 'failed' => 0], $again);
        self::assertCount(2, $this->sent, 'A delivered alert is never sent twice.');
    }

    public function testAFailureIsRetriedHourlyUntilItsRetriesRunOut(): void
    {
        $this->channelWorks = false;
        $alert = new AlertFired('quiet', 'tok', self::SINCE);

        $this->deliver($alert, '2026-11-17T09:00:00Z');
        self::assertStringEndsWith('Will retry at the next hourly check.', $this->records[0]);

        $this->records = [];
        $this->deliver($alert, '2026-11-18T08:00:00Z');
        self::assertStringEndsWith('Gave up: no more retries for this alert.', $this->records[0]);

        $this->records = [];
        $counts = $this->deliver($alert, '2026-11-18T09:00:00Z');
        self::assertSame(['sent' => 0, 'suppressed' => 0, 'failed' => 0], $counts);
        self::assertSame([], $this->records);
    }

    public function testARecoveryGoesOnlyWhereTheFiringAlertWent(): void
    {
        $firingId = AlertIds::alert(self::INSTALLATION, 'quiet', 'tok', 'firing', self::SINCE);
        $this->delivered['quiet:hub:tok'] = $firingId;
        $recovered = new AlertRecovered('quiet', 'tok', '2026-11-17T12:00:00Z', self::SINCE);

        $counts = $this->deliver($recovered, '2026-11-17T12:00:00Z');

        self::assertSame(['sent' => 1, 'suppressed' => 1, 'failed' => 0], $counts);
        self::assertSame(['hub recovered'], $this->sent);
        self::assertStringContainsString('never reached this destination', $this->records[1]);
    }

    /** @return array{sent: bool, reason: string} */
    public function send(string $destinationId, string $type): array
    {
        if (!$this->channelWorks) {
            return ['sent' => false, 'reason' => 'Receiver answered HTTP 503.'];
        }
        $this->sent[] = $destinationId.' '.$type;

        return ['sent' => true, 'reason' => 'Delivered.'];
    }

    public function lastDelivered(string $subject): ?string
    {
        return $this->delivered[$subject] ?? null;
    }

    public function markDelivered(string $subject, string $alertId): void
    {
        $this->delivered[$subject] = $alertId;
    }

    public function record(string $line): void
    {
        $this->records[] = $line;
    }

    /** @return array{sent: int, suppressed: int, failed: int} */
    private function deliver(AlertFired|AlertRecovered $alert, string $completedHour): array
    {
        $counts = ['sent' => 0, 'suppressed' => 0, 'failed' => 0];
        (new DeliverAlert($this->channel(), $this->log()))->deliver(
            $alert,
            [
                'id' => 'quiet',
                'enabled' => true,
                'covers' => 'all',
                'source' => 'events',
                'privacy_mode' => 'all',
                'event_name' => '',
                'condition' => 'no_events',
                'threshold' => 0,
                'window' => 'hour',
                'destinations' => ['hub', 'mail'],
            ],
            ['token' => 'tok', 'name' => 'Site', 'domain' => 'site.example.com'],
            [
                'hub' => ['id' => 'hub', 'label' => 'Hub', 'type' => 'webhook', 'url' => 'https://h.example/in'],
                'mail' => ['id' => 'mail', 'label' => 'Mail', 'type' => 'email', 'recipients' => ['a@example.com']],
            ],
            self::INSTALLATION,
            new \DateTimeImmutable($completedHour, new \DateTimeZone('UTC')),
            true,
            $counts,
        );

        return $counts;
    }

    private function channel(): AlertChannel
    {
        return new class($this) implements AlertChannel {
            public function __construct(private readonly DeliverAlertTest $test)
            {
            }

            public function send(array $destination, array $payload, string $conditionDescription): array
            {
                return $this->test->send($destination['id'], $payload['type']);
            }
        };
    }

    private function log(): DeliveryLog
    {
        return new class($this) implements DeliveryLog {
            public function __construct(private readonly DeliverAlertTest $test)
            {
            }

            public function lastDelivered(string $subject): ?string
            {
                return $this->test->lastDelivered($subject);
            }

            public function markDelivered(string $subject, string $alertId, string $details): void
            {
                $this->test->markDelivered($subject, $alertId);
            }

            public function record(
                string $operation,
                string $outcome,
                string $ruleId,
                string $websiteHost,
                string $destinationLabel,
                string $details,
            ): void {
                $this->test->record($outcome.' '.$destinationLabel.': '.$details);
            }
        };
    }
}
