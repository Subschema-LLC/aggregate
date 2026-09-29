<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Update\MaintenanceMode;
use App\Service\Update\UpdateJournal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:updates:maintenance', description: 'Show, turn on or turn off the update maintenance page')]
final class UpdateMaintenanceCommand extends Command
{
    public function __construct(private readonly MaintenanceMode $maintenance, private readonly UpdateJournal $journal)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'status, on or off', 'status')
            ->setHelp('While on, every web request (including /api/*) receives a 503 response. Updates turn it on and off automatically; use this to end it after resolving a failed update, or to pause the site yourself. "on" stays active until turned off.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        try {
            switch ($action) {
                case 'on':
                    $this->maintenance->hold('manual');
                    $io->success('Maintenance mode is on. Web requests receive a 503 page.');

                    return Command::SUCCESS;
                case 'off':
                    if ($this->journal->isLocked()) {
                        $io->error('An update is running. Wait for it to finish before turning maintenance mode off.');

                        return Command::FAILURE;
                    }
                    $this->maintenance->disable();
                    $io->success('Maintenance mode is off.');

                    return Command::SUCCESS;
                case 'status':
                    $status = $this->maintenance->status();
                    if ($status === null || !$status['active']) {
                        $io->text('Maintenance mode is off.');
                    } else {
                        $io->text(sprintf(
                            'Maintenance mode is on (%s) since %s UTC%s.',
                            $status['reason'] ?? 'unknown reason',
                            gmdate('Y-m-d H:i', (int) $status['since']),
                            $status['expires_at'] === null ? ', until turned off' : ', renewed while the update runs',
                        ));
                    }

                    return Command::SUCCESS;
            }
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->error('Use status, on or off.');

        return Command::INVALID;
    }
}
