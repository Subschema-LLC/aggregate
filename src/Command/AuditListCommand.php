<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Operations\AdminActions;
use App\Service\Operations\AuditTrailFilter;
use App\Service\Operations\AuditTrailLog;
use App\Service\Operations\AuditTrailSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The audit trail on the command line, with the Audit trail page's filters.
 * Reading it records nothing.
 */
#[AsCommand(
    name: 'app:audit:list',
    description: 'List audit trail entries: administrator actions, background job runs and sign-ins, with the Audit trail page’s filters',
)]
final class AuditListCommand extends Command
{
    private const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly AuditTrailLog $log,
        private readonly AuditTrailSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $areas = implode(', ', array_keys(AuditTrailSettings::AREAS));
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First day to list, YYYY-MM-DD (UTC)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last day to list, YYYY-MM-DD (UTC)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'List the last N days, today included (instead of --from)')
            ->addOption('area', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this area; repeat for several: '.$areas)
            ->addOption('action', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this action code, such as user_created; repeat for several')
            ->addOption('outcome', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this outcome: succeeded, failed, denied or started; repeat for several')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Only this user, exactly as recorded; "'.AuditTrailFilter::NO_USER.'" for entries without one')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Only subjects containing this text')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Only entries containing this text in their action, user, subject or details')
            ->addOption('task', null, InputOption::VALUE_REQUIRED, 'Only the entry of this job run (processing task number)')
            ->addOption('oldest-first', null, InputOption::VALUE_NONE, 'List the oldest entries first')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('List at most this many entries (1–%d)', AuditTrailLog::MAX_EXPORT), (string) self::DEFAULT_LIMIT)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the entries as a JSON array')
            ->addOption('summary', null, InputOption::VALUE_NONE, 'Show what each area records and how many entries it has, instead of the entries')
            ->setHelp(<<<'HELP'
                Lists the newest entries first. Times are UTC. Examples:

                  <info>php bin/console app:audit:list --days=7 --area=settings --area=users</info>
                  <info>php bin/console app:audit:list --outcome=failed --outcome=denied --json</info>
                  <info>php bin/console app:audit:list --user=scott --search=feature_flags</info>
                  <info>php bin/console app:audit:list --summary</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('summary') === true) {
            return $this->summary($io);
        }

        $from = $input->getOption('from');
        $days = $input->getOption('days');
        if ($days !== null) {
            if (!is_string($days) || preg_match('/^[1-9][0-9]{0,4}$/D', $days) !== 1) {
                $io->error('--days must be a whole number of days, at least 1.');

                return Command::INVALID;
            }
            if ($from !== null) {
                $io->error('Use --days or --from, not both.');

                return Command::INVALID;
            }
            $from = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-'.((int) $days - 1).' days')->format('Y-m-d');
        }
        $limit = $input->getOption('limit');
        if (!is_string($limit) || preg_match('/^[1-9][0-9]{0,5}$/D', $limit) !== 1 || (int) $limit > AuditTrailLog::MAX_EXPORT) {
            $io->error(sprintf('--limit must be a whole number from 1 to %d.', AuditTrailLog::MAX_EXPORT));

            return Command::INVALID;
        }
        $filter = AuditTrailFilter::fromQuery([
            'from' => $from,
            'to' => $input->getOption('to'),
            'area' => $input->getOption('area'),
            'action' => $input->getOption('action'),
            'outcome' => $input->getOption('outcome'),
            'user' => $input->getOption('user'),
            'subject' => $input->getOption('subject'),
            'q' => $input->getOption('search'),
            'task' => $input->getOption('task'),
            'sort' => $input->getOption('oldest-first') === true ? 'oldest' : 'newest',
        ], $problems);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $io->error($problem);
            }

            return Command::INVALID;
        }

        $entries = $this->log->each($filter, (int) $limit);
        if ($input->getOption('json') === true) {
            $output->write('[');
            $first = true;
            foreach ($entries as $entry) {
                $output->write(($first ? "\n" : ",\n").json_encode(['occurred_at' => $entry['occurred_at']->format(\DATE_ATOM)] + $entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $first = false;
            }
            $output->writeln($first ? ']' : "\n]");

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                $entry['occurred_at']->format('Y-m-d H:i:s'),
                $entry['area'],
                AdminActions::label($entry['action']),
                $entry['outcome'],
                $entry['user'] ?? '',
                $entry['subject'] ?? '',
                $entry['details'] ?? '',
            ];
        }
        if ($rows === []) {
            $io->writeln($filter->isFiltered() ? 'No entries match these filters.' : 'Nothing has been recorded yet.');

            return Command::SUCCESS;
        }
        $io->table(['Time (UTC)', 'Area', 'Action', 'Outcome', 'User', 'Subject', 'Details'], $rows);
        if (count($rows) === (int) $limit) {
            $io->note(sprintf('Showing the first %d. Use --limit to list more, or narrow the filters.', count($rows)));
        }

        return Command::SUCCESS;
    }

    private function summary(SymfonyStyle $io): int
    {
        $status = Command::SUCCESS;
        try {
            $switches = $this->settings->all();
        } catch (\InvalidArgumentException $e) {
            $io->warning($e->getMessage().' Every area is recorded as by default until it is corrected.');
            $switches = AuditTrailSettings::defaults();
            $status = Command::FAILURE;
        }
        $counts = $this->log->summary();
        $rows = [];
        foreach (AuditTrailSettings::AREAS as $area => $definition) {
            $rows[] = [
                $area,
                $definition['label'],
                $switches[$area] ? 'yes' : 'no',
                (string) ($counts[$area]['count'] ?? 0),
                isset($counts[$area]) ? $counts[$area]['oldest']->format('Y-m-d') : '',
                isset($counts[$area]) ? $counts[$area]['newest']->format('Y-m-d') : '',
            ];
        }
        $io->table(['Area', 'Label', 'Recorded', 'Entries', 'Oldest', 'Newest'], $rows);
        $io->writeln('Turn areas on and off on Administration → Audit trail, or under '.AuditTrailSettings::KEY.' in config/aggregate.yaml.');

        return $status;
    }
}
