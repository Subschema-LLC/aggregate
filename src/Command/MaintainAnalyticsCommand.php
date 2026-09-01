<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AnalyticsMaintenanceAlreadyRunning;
use App\Service\AnalyticsMaintenanceRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:analytics:maintain',
    description: 'Archive and delete analytics data according to the configured lifecycle policy',
)]
final class MaintainAnalyticsCommand extends Command
{
    public function __construct(
        private readonly AnalyticsMaintenanceRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Count eligible data without archiving or deleting it',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $result = $this->runner->run($dryRun);
        } catch (AnalyticsMaintenanceAlreadyRunning $e) {
            $io->warning($e->getMessage());

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('Analytics maintenance failed.', ['exception' => $e]);
            $io->error([
                'Analytics maintenance failed; no deletion step continued past the failure.',
                $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        if (!$result->archivingEnabled && !$result->retentionEnabled) {
            $io->success('Analytics archiving and retention are disabled; nothing changed.');

            return Command::SUCCESS;
        }

        $verb = $result->dryRun ? 'Eligible (no changes made)' : 'Processed';
        $io->table(
            ['Policy', $verb],
            [
                ['Raw events to archive', (string) $result->archivedEvents],
                ['Raw events to delete', (string) $result->deletedRawEvents],
                ['Archive cells to delete', (string) $result->deletedArchiveCells],
            ],
        );
        $io->success($result->dryRun
            ? 'Analytics maintenance dry run completed.'
            : 'Analytics maintenance completed.');

        return Command::SUCCESS;
    }
}
