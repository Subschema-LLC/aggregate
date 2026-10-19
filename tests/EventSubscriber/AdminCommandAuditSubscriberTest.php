<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\AdminCommandAuditSubscriber;
use App\Service\Operations\AdminActions;
use App\Service\Operations\ConfigurationChanges;
use App\Tests\Support\RecordingAdminAudit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AdminCommandAuditSubscriberTest extends TestCase
{
    /** @var array{0: array<mixed>, 1: array<mixed>, 2: string|false} */
    private array $environment;

    protected function setUp(): void
    {
        RecordingAdminAudit::$entries = [];
        $this->environment = [$_ENV, $_SERVER, getenv(AdminActions::CHILD_PROCESS)];
        unset($_ENV[AdminActions::CHILD_PROCESS], $_SERVER[AdminActions::CHILD_PROCESS]);
        putenv(AdminActions::CHILD_PROCESS);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER, $child] = $this->environment;
        putenv($child === false ? AdminActions::CHILD_PROCESS : AdminActions::CHILD_PROCESS.'='.$child);
        ConfigurationChanges::finish();
    }

    public function testItListensToEveryCommandAndItsEnd(): void
    {
        self::assertSame([ConsoleEvents::COMMAND => 'onCommand', ConsoleEvents::TERMINATE => 'onTerminate'], AdminCommandAuditSubscriber::getSubscribedEvents());
    }

    public function testACommandIsRecordedWhenItEndsWithItsExitStatusAndChanges(): void
    {
        $subscriber = new AdminCommandAuditSubscriber(new RecordingAdminAudit(), new RequestStack());
        [$command, $input] = $this->groupsCommand(['action' => 'create-group', '--name' => 'Brand A']);

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, new NullOutput()));
        ConfigurationChanges::record('config/website-scopes.yaml', ['groups.brand-a']);
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, new NullOutput(), Command::SUCCESS));
        [$command, $input] = $this->groupsCommand(['action' => 'delete-group', '--id' => 'brand-a']);
        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, new NullOutput()));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, new NullOutput(), Command::FAILURE));

        self::assertSame([
            ['websites', 'group_created', 'succeeded', 'Brand A', 'Command app:website-scopes. Changed website-scopes.yaml: groups.brand-a'],
            ['websites', 'group_deleted', 'failed', 'brand-a', 'Command app:website-scopes.'],
        ], array_map(static fn (array $entry): array => [$entry['area'], $entry['action'], $entry['outcome'], $entry['subject'], $entry['details']], RecordingAdminAudit::$entries));
        self::assertStringEndsWith(' (command line)', (string) RecordingAdminAudit::$entries[0]['actor']);
        self::assertSame(AdminCommandAuditSubscriber::operator(), RecordingAdminAudit::$entries[0]['actor']);
    }

    public function testAnUpdateIsRecordedWhenItStarts(): void
    {
        $subscriber = new AdminCommandAuditSubscriber(new RecordingAdminAudit());
        $command = (new Command('app:updates:rollback'))->addOption('yes', 'y', InputOption::VALUE_NONE);
        $input = new ArrayInput(['--yes' => true], $command->getDefinition());

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, new NullOutput()));
        self::assertSame([['updates', 'update_rolled_back', 'started']], array_map(static fn (array $entry): array => [$entry['area'], $entry['action'], $entry['outcome']], RecordingAdminAudit::$entries));
        self::assertFalse(ConfigurationChanges::isRecording());
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, new NullOutput(), Command::SUCCESS));
        self::assertCount(1, RecordingAdminAudit::$entries);
    }

    public function testCommandsTheApplicationStartedOrThatRunInsideARequestAreNotRecordedAgain(): void
    {
        [$command, $input] = $this->groupsCommand(['action' => 'label']);

        $_SERVER[AdminActions::CHILD_PROCESS] = '1';
        $subscriber = new AdminCommandAuditSubscriber(new RecordingAdminAudit());
        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, new NullOutput()));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, new NullOutput(), Command::SUCCESS));
        unset($_SERVER[AdminActions::CHILD_PROCESS]);

        $requests = new RequestStack();
        $requests->push(new Request());
        $subscriber = new AdminCommandAuditSubscriber(new RecordingAdminAudit(), $requests);
        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, new NullOutput()));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, new NullOutput(), Command::SUCCESS));

        self::assertSame([], RecordingAdminAudit::$entries);
        self::assertFalse(ConfigurationChanges::isRecording(), 'the web request’s own recording is left alone');
    }

    public function testOnlyTheOutermostCommandIsRecorded(): void
    {
        $subscriber = new AdminCommandAuditSubscriber(new RecordingAdminAudit());
        $outer = new Command('app:install');
        $outer->addOption('username', null, InputOption::VALUE_REQUIRED)->addOption('password', null, InputOption::VALUE_REQUIRED);
        $outerInput = new ArrayInput(['--username' => 'admin', '--password' => 'secret-value'], $outer->getDefinition());
        [$inner, $innerInput] = $this->groupsCommand(['action' => 'label']);

        $subscriber->onCommand(new ConsoleCommandEvent($outer, $outerInput, new NullOutput()));
        $subscriber->onCommand(new ConsoleCommandEvent($inner, $innerInput, new NullOutput()));
        $subscriber->onTerminate(new ConsoleTerminateEvent($inner, $innerInput, new NullOutput(), Command::SUCCESS));
        self::assertSame([], RecordingAdminAudit::$entries);
        $subscriber->onTerminate(new ConsoleTerminateEvent($outer, $outerInput, new NullOutput(), Command::SUCCESS));

        self::assertSame([['users', 'installed', 'succeeded', 'admin']], array_map(static fn (array $entry): array => [$entry['area'], $entry['action'], $entry['outcome'], $entry['subject']], RecordingAdminAudit::$entries));
        self::assertStringNotContainsString('secret-value', (string) json_encode(RecordingAdminAudit::$entries));
    }

    public function testReadingCommandsAreNotRecorded(): void
    {
        $subscriber = new AdminCommandAuditSubscriber(new RecordingAdminAudit());
        [$command, $input] = $this->groupsCommand(['action' => 'list']);
        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, new NullOutput()));
        self::assertFalse(ConfigurationChanges::isRecording());
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, new NullOutput(), Command::SUCCESS));

        $other = new Command('app:audit:list');
        $otherInput = new ArrayInput([], $other->getDefinition());
        $subscriber->onCommand(new ConsoleCommandEvent($other, $otherInput, new NullOutput()));
        $subscriber->onTerminate(new ConsoleTerminateEvent($other, $otherInput, new NullOutput(), Command::SUCCESS));

        self::assertSame([], RecordingAdminAudit::$entries);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: Command, 1: ArrayInput}
     */
    private function groupsCommand(array $arguments): array
    {
        $command = new Command('app:website-scopes');
        $command->addArgument('action', InputArgument::OPTIONAL, '', 'list')
            ->addOption('name', null, InputOption::VALUE_REQUIRED)
            ->addOption('id', null, InputOption::VALUE_REQUIRED);

        return [$command, new ArrayInput($arguments, $command->getDefinition())];
    }
}
