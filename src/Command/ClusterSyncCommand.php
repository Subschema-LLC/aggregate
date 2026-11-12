<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Cluster\ClusterSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:cluster:sync',
    description: 'Every minute on every server of a cluster: publish the configuration (administration) or pull it (collection, worker)',
)]
final class ClusterSyncCommand extends Command
{
    /** Held while a run lasts, so a slow pull and the next minute's run never overlap. */
    public const LOCK_FILE = 'var/cluster/sync.lock';

    public function __construct(
        private readonly ClusterSync $sync,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Run it every minute on every server of a cluster, from cron or a systemd timer, as the
            user the web server runs as. On the administration server it records the configuration's
            version and alerts when a server stops pulling; on collection and worker servers it pulls
            the configuration and records whether it is current. A server that cannot confirm its
            configuration for cluster_config_max_age_minutes (5 by default) pauses collection.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // A plain file lock: symfony/lock is not a dependency.
        $path = $this->projectDir.'/'.self::LOCK_FILE;
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            $output->writeln('<error>'.dirname($path).' could not be created.</error>');

            return Command::FAILURE;
        }
        $lock = @fopen($path, 'c');
        if ($lock === false) {
            $output->writeln('<error>'.$path.' could not be opened.</error>');

            return Command::FAILURE;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $output->writeln('The previous app:cluster:sync is still running.');

            return Command::SUCCESS;
        }
        try {
            $result = $this->sync->run();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        foreach ($result['messages'] as $message) {
            $output->writeln($message, $result['ok'] ? OutputInterface::VERBOSITY_NORMAL : OutputInterface::VERBOSITY_QUIET);
        }

        return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
    }
}
