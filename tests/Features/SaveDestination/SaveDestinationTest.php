<?php

declare(strict_types=1);

namespace App\Tests\Features\SaveDestination;

use App\Entities\Destination\Destination;
use App\Entities\Destination\Destinations;
use App\Features\SaveDestination\DestinationAlreadyExists;
use App\Features\SaveDestination\DestinationNotFound;
use App\Features\SaveDestination\SaveDestination;
use App\Features\SaveDestination\SecretChange;
use PHPUnit\Framework\TestCase;

final class SaveDestinationTest extends TestCase
{
    private FakeSecrets $secrets;
    /** @var array<string, array<string, mixed>> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->secrets = new FakeSecrets();
    }

    public function testCreatesFromTheLabelAndGeneratesASecretOnce(): void
    {
        $result = $this->feature()->create(self::hook(['id' => '']), SecretChange::generate());

        self::assertSame('ops-hub', $result['destination']['id']);
        self::assertSame('generated-secret', $result['generated_secret']);
        self::assertSame(['ops-hub' => 'generated-secret'], $this->secrets->saved);
    }

    public function testCreateRefusesATakenIdAndEditNeedsAnExistingOne(): void
    {
        $this->feature()->create(self::hook(), SecretChange::keep());

        $this->expectException(DestinationAlreadyExists::class);
        $this->feature()->create(self::hook(), SecretChange::keep());
    }

    public function testEditingAMissingDestinationIsRefused(): void
    {
        $this->expectException(DestinationNotFound::class);
        $this->feature()->edit(self::hook(), SecretChange::keep());
    }

    public function testABlankUrlOnEditKeepsTheSavedOne(): void
    {
        $this->feature()->create(self::hook(), SecretChange::keep());
        $edited = $this->feature()->edit(self::hook(['url' => '', 'label' => 'Renamed']), SecretChange::set(' '));

        self::assertSame('https://hooks.example.com/in', $edited['destination']['url'] ?? null);
        self::assertSame('Renamed', $edited['destination']['label']);
        self::assertSame([], $this->secrets->saved, 'A blank secret keeps the saved one.');
    }

    public function testASecretTheEnvironmentSetsIsNeverChanged(): void
    {
        $this->secrets->environment['ops-hub'] = 'from-env';

        try {
            $this->feature()->create(self::hook(), SecretChange::set('typed'));
            self::fail('The secret must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ALERTS_WEBHOOK_SECRET_OPS-HUB', $e->getMessage());
        }
        self::assertSame([], $this->saved, 'Nothing is saved when the secret is refused.');
    }

    public function testAWebhookTurnedIntoEmailDropsItsSecret(): void
    {
        $this->feature()->create(self::hook(), SecretChange::generate());
        $email = ['id' => 'ops-hub', 'label' => 'Ops hub', 'type' => 'email', 'recipients' => 'ops@example.com'];
        $result = $this->feature()->edit($email, SecretChange::generate());

        self::assertSame([], $this->secrets->saved);
        self::assertNull($result['generated_secret'], 'An email destination never gets a secret.');
    }

    private function feature(): SaveDestination
    {
        $store = new class($this->saved) implements Destinations {
            /** @param array<string, array<string, mixed>> $saved */
            public function __construct(private array &$saved)
            {
            }

            public function find(string $id): ?array
            {
                return isset($this->saved[$id]) ? Destination::fromInput($this->saved[$id])->toArray() : null;
            }

            public function save(array $candidate): array
            {
                $destination = Destination::fromInput($candidate)->toArray();
                $this->saved[$destination['id']] = $candidate;

                return $destination;
            }

            public function delete(string $id): void
            {
                unset($this->saved[$id]);
            }
        };

        return new SaveDestination($store, $this->secrets);
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    private static function hook(array $change = []): array
    {
        return [...['id' => 'ops-hub', 'label' => 'Ops hub', 'type' => 'webhook', 'url' => 'https://hooks.example.com/in'], ...$change];
    }
}
