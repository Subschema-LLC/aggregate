<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\EventExampleGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:analytics:examples',
    description: 'Export synthetic collection examples from the saved data model as JSON',
)]
final class ExportEventExamplesCommand extends Command
{
    public function __construct(private readonly EventExampleGenerator $generator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Examples to include: all, anonymous, or enhanced', 'all')
            ->addOption('example', null, InputOption::VALUE_REQUIRED, 'Source: model (saved configuration) or ecommerce (unsaved recommendation)', 'model')
            ->setHelp('Writes a JSON bundle with synthetic request payloads and consent requirements to stdout. The generator reads configuration without querying events, changing settings, or sending requests. The dashboard may be disabled. Normal Symfony/Doctrine startup still needs infrastructure configuration; set the documented DATABASE_URL serverVersion to avoid database-version discovery. Replace the public website token placeholder before testing ingestion; enhanced collection requires an actual explicit consent choice.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $mode = $input->getOption('mode');
        $example = $input->getOption('example');
        if (!is_string($mode) || !in_array($mode, ['all', 'anonymous', 'enhanced'], true)) {
            $errors->writeln('Choose --mode=all, --mode=anonymous, or --mode=enhanced.', OutputInterface::OUTPUT_RAW);

            return Command::INVALID;
        }
        if (!is_string($example) || !in_array($example, ['model', 'ecommerce'], true)) {
            $errors->writeln('Choose --example=model or --example=ecommerce.', OutputInterface::OUTPUT_RAW);

            return Command::INVALID;
        }

        try {
            // Build the entire document before writing any stdout bytes.
            $json = $this->generator->exportJson($mode, $example);
        } catch (\Throwable) {
            // YAML/parser errors may include operator values; do not echo them.
            $errors->writeln('Event examples could not be generated. Check the saved data model and application configuration.', OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
        $output->write($json, false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
