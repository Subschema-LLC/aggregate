<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Operations\AdminActions;
use App\Service\Operations\AdminAudit;
use App\Service\Operations\ConfigurationChanges;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records the console commands AdminActions lists as administrator actions,
 * with the operating-system user who ran them and their exit status.
 *
 * Not recorded: commands the application starts itself (they carry
 * AdminActions::CHILD_PROCESS, and the action that started them was recorded),
 * commands run inside a web request (the installer runs migrations that way,
 * and the request is recorded), and commands another command runs. Updates
 * are recorded when they start, because they replace this process's files.
 */
final class AdminCommandAuditSubscriber implements EventSubscriberInterface
{
    private int $depth = 0;
    /** @var array{area: string, action: string, at: 'start'|'end', subject: ?string}|null */
    private ?array $pending = null;

    public function __construct(
        private readonly AdminAudit $audit,
        private readonly ?RequestStack $requests = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        ++$this->depth;
        if ($this->depth !== 1 || $this->startedByTheApplication() || $this->requests?->getMainRequest() !== null) {
            return;
        }
        $name = $event->getCommand()?->getName();
        $action = is_string($name) ? AdminActions::forCommand($name, $event->getInput()) : null;
        if ($action === null) {
            return;
        }
        if ($action['at'] === 'start') {
            $this->audit->record($action['area'], $action['action'], AdminAudit::STARTED, self::operator(), $action['subject'], 'Command '.$name.'.');

            return;
        }
        $this->pending = $action;
        ConfigurationChanges::start();
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $this->depth = max(0, $this->depth - 1);
        $action = $this->pending;
        if ($this->depth !== 0 || $action === null) {
            return;
        }
        $this->pending = null;
        $changes = ConfigurationChanges::describe(ConfigurationChanges::finish());
        $name = (string) $event->getCommand()?->getName();
        $this->audit->record(
            $action['area'],
            $action['action'],
            $event->getExitCode() === 0 ? AdminAudit::SUCCEEDED : AdminAudit::FAILED,
            self::operator(),
            $action['subject'],
            'Command '.$name.'.'.($changes !== null ? ' '.$changes : ''),
            in_array($action['action'], AdminActions::ALWAYS, true),
        );
    }

    /** Who ran the command: the operating-system account, marked as the command line. */
    public static function operator(): string
    {
        $user = null;
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $entry = @posix_getpwuid(posix_geteuid());
            $user = is_array($entry) ? $entry['name'] : null;
        }
        $user ??= get_current_user();

        return ($user !== '' ? $user : 'unknown').' (command line)';
    }

    private function startedByTheApplication(): bool
    {
        $value = $_SERVER[AdminActions::CHILD_PROCESS] ?? $_ENV[AdminActions::CHILD_PROCESS] ?? getenv(AdminActions::CHILD_PROCESS);

        return is_string($value) && $value !== '' && $value !== '0';
    }
}
