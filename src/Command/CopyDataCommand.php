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

#[AsCommand(name: 'app:data:copy', description: 'Copy collected rows into another database')]
final class CopyDataCommand extends Command
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
            ->addOption('dataset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'events, archives, or users')
            ->addOption('website-token', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit the copy to these website tokens')
            ->addOption('map-token', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Rewrite a website token, as old=new')
            ->addOption('include-users', null, InputOption::VALUE_NONE, 'Copy password hashes. Leave this off unless the target should have the same accounts.')
            ->addOption('on-conflict', null, InputOption::VALUE_REQUIRED, 'skip, replace, or fail', 'fail')
            ->addOption('new-ids', null, InputOption::VALUE_NONE, 'Assign new ids for events and users, and write an id map')
            ->addOption('consistent', null, InputOption::VALUE_NONE, 'Read inside one transaction. Pause collection first; this can block writers.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and count rows without writing them')
            ->addOption('requested-by', null, InputOption::VALUE_REQUIRED, 'Administrator username when the dashboard starts this command')
            ->setHelp('Reads AGGREGATE_TRANSFER_TARGET_URL from the environment. Do not pass the database URL as an option. See docs/DATA-TRANSFER.md.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = $this->targetUrl();
        if ($url === null) {
            $io->error('Set '.DataTransfer::TARGET_URL_ENV.' to the target database URL. Do not pass the URL as an option.');

            return Command::FAILURE;
        }
        try {
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

        return $this->console->run($io, $trigger, fn () => $this->transfer->copy(
            $url,
            $datasets,
            $tokens,
            $map,
            $includeUsers,
            $onConflict,
            (bool) $input->getOption('new-ids'),
            (bool) $input->getOption('consistent'),
            (bool) $input->getOption('dry-run'),
        ));
    }

    private function targetUrl(): ?string
    {
        foreach ([getenv(DataTransfer::TARGET_URL_ENV), $_ENV[DataTransfer::TARGET_URL_ENV] ?? null, $_SERVER[DataTransfer::TARGET_URL_ENV] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
