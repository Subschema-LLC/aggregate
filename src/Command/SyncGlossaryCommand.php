<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Glossary\GlossarySync;
use App\Service\Glossary\GlossaryValidationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:analytics:glossary:sync', description: 'Publish declared BI value labels and column definitions')]
final class SyncGlossaryCommand extends Command
{
    public function __construct(private readonly GlossarySync $glossary)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print rows to insert, change, and delete without writing')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Exit 1 when the database differs from declared configuration')
            ->addOption('export', null, InputOption::VALUE_NONE, 'Export every resolved row as CSV without accessing the database')
            ->addOption('missing-only', null, InputOption::VALUE_NONE, 'With --export, include only fallback labels')
            ->setHelp('The glossary publishes declared metadata only. Run after migrations, goal configuration/cache changes, or bi_glossary YAML edits. No event data is read.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $modes = array_filter(['dry-run', 'check', 'export'], static fn (string $mode): bool => (bool) $input->getOption($mode));
        if (count($modes) > 1) {
            $io->error('Choose only one of --dry-run, --check, or --export.');

            return Command::INVALID;
        }
        if ($input->getOption('missing-only') && !$input->getOption('export')) {
            $io->error('--missing-only requires --export.');

            return Command::INVALID;
        }

        try {
            if ($input->getOption('export')) {
                $output->write(GlossarySync::csv($this->glossary->rows(), (bool) $input->getOption('missing-only')), false, OutputInterface::OUTPUT_RAW);

                return Command::SUCCESS;
            }
            if ($input->getOption('dry-run') || $input->getOption('check')) {
                $diff = $this->glossary->diff();
                $io->text(sprintf('%d rows to insert, %d to change, %d to delete (%d resolved rows).', $diff['insert'], $diff['change'], $diff['delete'], $diff['total']));
                if ($input->getOption('check')) {
                    $io->text($diff['changed'] ? 'Database differs from configuration.' : 'In sync.');

                    return $diff['changed'] ? Command::FAILURE : Command::SUCCESS;
                }

                return Command::SUCCESS;
            }
            $summary = $this->glossary->sync();
            $fallbackDetails = [];
            foreach ($summary['fallback_counts'] as $locale => $count) {
                if ($count > 0) {
                    $fallbackDetails[] = $locale.': '.$count;
                }
            }
            $io->success(sprintf(
                '%s %d rows: %d dimensions, %d locales, %d fallback labels%s.',
                $summary['changed'] ? 'Synced' : 'Already in sync;',
                $summary['written'], $summary['dimensions'], count($summary['locales']),
                array_sum($summary['fallback_counts']),
                $fallbackDetails === [] ? '' : ' ('.implode(', ', $fallbackDetails).')',
            ));

            return Command::SUCCESS;
        } catch (GlossaryValidationException $exception) {
            foreach ($exception->errors() as $field => $message) {
                $io->error(OutputFormatter::escape($field.': '.$message));
            }

            return Command::INVALID;
        } catch (\InvalidArgumentException $exception) {
            $io->error(OutputFormatter::escape($exception->getMessage()));

            return Command::INVALID;
        } catch (\Throwable $exception) {
            $io->error(OutputFormatter::escape($exception->getMessage()));

            return Command::FAILURE;
        }
    }
}
