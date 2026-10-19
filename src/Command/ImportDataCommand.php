<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DataTransfer\DataTransfer;
use App\Service\DataTransfer\DataTransferException;
use App\Service\DataTransfer\TransferConsole;
use App\Service\DataTransfer\TransferOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:data:import', description: 'Import a private data package into this installation')]
final class ImportDataCommand extends Command
{
    public function __construct(
        private readonly DataTransfer $transfer,
        private readonly TransferConsole $console,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Package directory name under var/data-transfer')
            ->addOption('dataset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'events, archives, or users')
            ->addOption('website-token', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Import only these website tokens')
            ->addOption('map-token', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Rewrite a website token, as old=new')
            ->addOption('include-users', null, InputOption::VALUE_NONE, 'Import password hashes. Leave this off unless these accounts should sign in here.')
            ->addOption('on-conflict', null, InputOption::VALUE_REQUIRED, 'skip, replace, or fail', 'fail')
            ->addOption('new-ids', null, InputOption::VALUE_NONE, 'Assign new ids for events and users, and write an id map')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and count rows without writing them')
            ->addOption('requested-by', null, InputOption::VALUE_REQUIRED, 'Administrator username when the dashboard starts this command')
            ->setHelp('Imports a package written by app:data:export. Configuration is not imported. See docs/DATA-TRANSFER.md.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $package = TransferOptions::packageName((string) $input->getOption('package'));
            $datasets = TransferOptions::stringList($input->getOption('dataset'));
            $tokens = TransferOptions::tokens(TransferOptions::stringList($input->getOption('website-token')));
            $map = TransferOptions::tokenMap(TransferOptions::stringList($input->getOption('map-token')));
            $includeUsers = (bool) $input->getOption('include-users') || in_array('users', $datasets, true);
            $onConflict = TransferOptions::onConflict((string) $input->getOption('on-conflict'));
            $trigger = $this->console->trigger($input);
        } catch (DataTransferException | \InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return $this->console->run($io, $trigger, fn () => $this->transfer->import(
            $package,
            $datasets,
            $tokens,
            $map,
            $includeUsers,
            $onConflict,
            (bool) $input->getOption('new-ids'),
            (bool) $input->getOption('dry-run'),
        ));
    }
}
