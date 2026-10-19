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

#[AsCommand(name: 'app:data:export', description: 'Copy collected rows into a private package another installation can import')]
final class ExportDataCommand extends Command
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
            ->addOption('website-token', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit the copy to these website tokens')
            ->addOption('include-users', null, InputOption::VALUE_NONE, 'Include password hashes. Leave this off unless the target should have the same accounts.')
            ->addOption('consistent', null, InputOption::VALUE_NONE, 'Read inside one transaction. Pause collection first; this can block writers.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace an existing package of the same name')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count and validate rows without writing a package')
            ->addOption('requested-by', null, InputOption::VALUE_REQUIRED, 'Administrator username when the dashboard starts this command')
            ->setHelp('Copies events and archive cells, and users only with --include-users. Configuration is not copied. See docs/DATA-TRANSFER.md.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $package = TransferOptions::packageName((string) $input->getOption('package'));
            $datasets = TransferOptions::stringList($input->getOption('dataset'));
            $tokens = TransferOptions::tokens(TransferOptions::stringList($input->getOption('website-token')));
            $includeUsers = (bool) $input->getOption('include-users') || in_array('users', $datasets, true);
            $trigger = $this->console->trigger($input);
        } catch (DataTransferException | \InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return $this->console->run($io, $trigger, fn () => $this->transfer->export(
            $package,
            $datasets,
            $tokens,
            $includeUsers,
            (bool) $input->getOption('consistent'),
            (bool) $input->getOption('force'),
            (bool) $input->getOption('dry-run'),
        ));
    }
}
