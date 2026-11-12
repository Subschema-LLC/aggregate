<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\Cluster\ServerRoleRefusal;
use App\Service\GoalDefinitions;
use App\Service\GoalEventRegistry;
use App\Service\Operations\ConfigurationChanges;
use App\Service\ParsedYamlCache;
use App\Service\PrivacySanitizer;
use App\Service\Settings\Section\GoalsSection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Goals are read when used, so a change made on the Goals page, by
 * app:config or by the configuration pull applies to the next request,
 * without the cache clear that compiling them into the container needed.
 */
final class GoalDefinitionsTest extends TestCase
{
    private const SHIPPED = "# Shipped defaults\nparameters:\n    app.goal_events:\n        purchase: { label: Purchase, enabled: true, anonymous: true }\n        lead: { label: Lead, enabled: true, anonymous: false }\n";

    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['SERVER_ROLE', 'DATABASE_URL'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->directory = sys_get_temp_dir().'/aggregate-goals-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->directory.'/'.GoalDefinitions::SHIPPED_FILE, self::SHIPPED);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testTheShippedGoalsApplyUntilALocalFileReplacesThemAsAWhole(): void
    {
        $goals = $this->goals();
        self::assertSame(['purchase', 'lead'], array_keys($goals->all()));
        self::assertSame(GoalDefinitions::SHIPPED_FILE, $goals->source());

        file_put_contents($this->directory.'/'.GoalDefinitions::LOCAL_FILE, "parameters:\n    app.goal_events:\n        newsletter: { label: Newsletter, enabled: true, anonymous: true }\n");
        self::assertSame(['newsletter'], array_keys($goals->all()), 'The local file replaces the shipped goals, read again without a new container.');
        self::assertSame(GoalDefinitions::LOCAL_FILE, $goals->source());

        file_put_contents($this->directory.'/'.GoalDefinitions::LOCAL_FILE, "parameters:\n    other.parameter: 1\n");
        self::assertSame(['purchase', 'lead'], array_keys($goals->all()), 'A local file without goals leaves the shipped ones.');
    }

    public function testAChangedFileReachesTheRegistryOnTheNextLookup(): void
    {
        $goals = $this->goals();
        $registry = new GoalEventRegistry(new PrivacySanitizer(), $goals);
        self::assertSame('purchase', $registry->resolve('purchase', anonymousMode: true));
        self::assertNull($registry->resolve('lead', anonymousMode: true), 'lead needs consent.');

        // As the configuration pull writes it on a collection server.
        file_put_contents($this->directory.'/'.GoalDefinitions::LOCAL_FILE, "parameters:\n    app.goal_events:\n        purchase: { label: Purchase, enabled: false, anonymous: true }\n");
        self::assertNull($registry->resolve('purchase', anonymousMode: true), 'A disabled goal stops at once.');

        file_put_contents($this->directory.'/'.GoalDefinitions::LOCAL_FILE, "parameters:\n    app.goal_events:\n        Not A Code: { label: Broken, enabled: true, anonymous: true }\n");
        self::assertNull($registry->resolve('purchase', anonymousMode: true), 'Goals that are not valid allow no goal; the event is still recorded.');
        $this->expectException(\InvalidArgumentException::class);
        $goals->all();
    }

    public function testSavingWritesTheLocalFileKeepsOtherParametersAndRecordsTheChange(): void
    {
        file_put_contents($this->directory.'/'.GoalDefinitions::LOCAL_FILE, "parameters:\n    other.parameter: keep\n");
        $goals = $this->goals();

        ConfigurationChanges::start();
        $saved = $goals->save(['signup' => ['label' => ' Signup ', 'enabled' => true, 'anonymous' => false]]);
        $changes = ConfigurationChanges::finish();

        self::assertSame(['signup' => ['label' => 'Signup', 'enabled' => true, 'anonymous' => false]], $saved);
        $document = Yaml::parseFile($this->directory.'/'.GoalDefinitions::LOCAL_FILE);
        self::assertSame('keep', $document['parameters']['other.parameter']);
        self::assertSame($saved, $document['parameters'][GoalDefinitions::PARAMETER]);
        self::assertSame(['signup'], array_keys($goals->all()));
        self::assertSame([GoalDefinitions::LOCAL_FILE => ['signup']], $changes);
        self::assertSame(self::SHIPPED, file_get_contents($this->directory.'/'.GoalDefinitions::SHIPPED_FILE), 'The shipped file, which updates replace, is never written.');
    }

    /** @param mixed $goals */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidGoals')]
    public function testInvalidGoalsAreRefusedAndNothingIsWritten(mixed $goals, string $message): void
    {
        try {
            $this->goals()->save($goals);
            self::fail('The goals should have been refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertFileDoesNotExist($this->directory.'/'.GoalDefinitions::LOCAL_FILE);
    }

    public static function invalidGoals(): iterable
    {
        yield 'a list' => [[['label' => 'x']], 'mapping of goal codes'];
        yield 'an unsafe code' => [['order 123' => ['label' => 'Order', 'enabled' => true, 'anonymous' => true]], 'safe event-name format'];
        yield 'no label' => [['purchase' => ['label' => ' ', 'enabled' => true, 'anonymous' => true]], 'non-empty label'];
        yield 'a string for enabled' => [['purchase' => ['label' => 'P', 'enabled' => 'yes', 'anonymous' => true]], 'boolean enabled'];
        yield 'an unknown option' => [['purchase' => ['label' => 'P', 'enabled' => true, 'anonymous' => true, 'value' => 5]], 'unsupported options'];
    }

    public function testCollectionAndWorkerServersDoNotWriteGoals(): void
    {
        $_ENV['SERVER_ROLE'] = $_SERVER['SERVER_ROLE'] = 'collection';
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'mysql://a:b@10.0.0.5/aggregate';

        $this->expectException(ServerRoleRefusal::class);
        $this->goals()->save(['purchase' => ['label' => 'Purchase', 'enabled' => true, 'anonymous' => true]]);
    }

    public function testTheGoalsSectionChecksAndSavesTheWayThePageDoes(): void
    {
        $section = new GoalsSection(new AggregateConfigLoader($this->directory, 'test'), $this->goals());
        $goals = ['purchase' => ['label' => 'Purchase', 'enabled' => true, 'anonymous' => true]];

        self::assertSame(['goals'], $section->keys());
        self::assertSame(['purchase', 'lead'], array_keys($section->values()['goals']));
        self::assertSame(['The values are valid. Nothing was saved (--check).'], $section->apply(['goals' => $goals], check: true));
        self::assertFileDoesNotExist($this->directory.'/'.GoalDefinitions::LOCAL_FILE);

        $messages = $section->apply(['goals' => $goals]);
        self::assertStringContainsString('Saved 1 goal to config/goals.local.yaml', $messages[0]);
        self::assertSame(['purchase'], array_keys($section->values()['goals']));

        $this->expectException(\InvalidArgumentException::class);
        $section->apply(['goals' => ['bad code' => []]]);
    }

    private function goals(): GoalDefinitions
    {
        return new GoalDefinitions($this->directory, new ParsedYamlCache($this->directory.'/var/cache/parsed-yaml'));
    }
}
