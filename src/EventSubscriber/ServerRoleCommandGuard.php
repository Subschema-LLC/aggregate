<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Cluster\ServerRole;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Refuses console commands this server's role leaves to another server:
 * migrations, updates, the scheduled task and every configuration writer on
 * collection, worker and balancer servers, naming the server that runs them.
 * `all` and `administration` run every command, as one server always has.
 */
final class ServerRoleCommandGuard implements EventSubscriberInterface
{
    public function __construct(private readonly ServerRole $role)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the other command listeners, so a refused command does nothing.
        return [ConsoleEvents::COMMAND => ['onCommand', 1024]];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();
        if (!is_string($name) || $this->role->allowsCommand($name)) {
            return;
        }
        $event->disableCommand();
        $output = $event->getOutput();
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }
        // Shown even with --quiet: the command did nothing, and this says why.
        $output->writeln('<error>'.$this->role->refusal($name).'</error>', OutputInterface::VERBOSITY_QUIET);
        $output->writeln('Run "php bin/console app:server:role" to see what this server does.');
    }
}
