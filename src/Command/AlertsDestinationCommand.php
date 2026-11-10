<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSecrets;
use App\Service\Alerts\AlertSettings;
use App\Service\FeatureFlags;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:alerts:destination',
    description: 'List, create, edit or delete alert destinations and webhook signing secrets',
    aliases: ['app:alerts:destinations'],
)]
final class AlertsDestinationCommand extends Command
{
    public function __construct(
        private readonly AlertSettings $settings,
        private readonly AlertSecrets $secrets,
        private readonly FeatureFlags $features,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'list, create, edit, delete or secret')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Destination ID slug (e.g. geektown-hub, ops-email)')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Destination display label (e.g. "Geektown Hub")')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Destination type: webhook or email')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'HTTPS webhook URL (webhook destinations only)')
            ->addOption('recipient', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Recipient email address (email destinations only, repeatable)')
            ->addOption('secret', null, InputOption::VALUE_REQUIRED, 'Webhook signing secret to save in config/secrets/alerts.yaml')
            ->addOption('generate-secret', null, InputOption::VALUE_NONE, 'Generate and store a new random webhook signing secret');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = strtolower(trim((string) $input->getArgument('action')));

        if ($action !== 'list') {
            try {
                $this->features->assertEnabled('alerts');
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        try {
            return match ($action) {
                'list' => $this->list($io),
                'create', 'add', 'edit', 'update' => $this->save($io, $input),
                'delete', 'remove' => $this->delete($io, $input),
                'secret' => $this->saveSecret($io, $input),
                default => $this->unknown($io),
            };
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function list(SymfonyStyle $io): int
    {
        $destinations = $this->settings->destinations();
        if ($destinations === []) {
            $io->text('No alert destinations.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($destinations as $dest) {
            $target = $dest['type'] === AlertRuleValidator::TYPE_WEBHOOK
                ? ($dest['host'] ?? '')
                : sprintf('%d listed recipient%s', count($dest['recipients'] ?? []), count($dest['recipients'] ?? []) === 1 ? '' : 's');
            $rows[] = [
                $dest['id'],
                $dest['label'],
                $dest['type'],
                $target,
            ];
        }
        $io->table(['ID', 'Label', 'Type', 'Target (redacted)'], $rows);

        return Command::SUCCESS;
    }

    private function save(SymfonyStyle $io, InputInterface $input): int
    {
        $id = $input->getOption('id') !== null ? trim((string) $input->getOption('id')) : null;
        $existing = $id !== null && $id !== '' ? $this->settings->destination($id) : null;

        $candidate = $existing ?? [];
        unset($candidate['host']);
        if ($id !== null && $id !== '') {
            $candidate['id'] = $id;
        }
        if ($input->getOption('label') !== null) {
            $candidate['label'] = (string) $input->getOption('label');
        }
        if ($input->getOption('type') !== null) {
            $candidate['type'] = (string) $input->getOption('type');
        }
        if ($input->getOption('url') !== null) {
            $candidate['url'] = (string) $input->getOption('url');
        }
        $recipients = $input->getOption('recipient');
        if (is_array($recipients) && $recipients !== []) {
            $candidate['recipients'] = $recipients;
        }

        $saved = $this->settings->saveDestination($candidate);
        if ($input->getOption('generate-secret') === true) {
            $generated = $this->secrets->generate($saved['id']);
            $io->writeln('Webhook signing secret (shown once, copy it now): '.$generated);
        } elseif ($input->getOption('secret') !== null) {
            $this->secrets->set((string) $input->getOption('secret'), $saved['id']);
        }

        $io->success(sprintf('Saved alert destination "%s" (%s).', $saved['label'], $saved['id']));

        return Command::SUCCESS;
    }

    private function delete(SymfonyStyle $io, InputInterface $input): int
    {
        $id = trim((string) ($input->getOption('id') ?? ''));
        $this->settings->deleteDestination($id);
        $this->secrets->remove($id);
        $io->success(sprintf('Deleted alert destination "%s".', $id));

        return Command::SUCCESS;
    }

    private function saveSecret(SymfonyStyle $io, InputInterface $input): int
    {
        $id = $input->getOption('id') !== null && trim((string) $input->getOption('id')) !== ''
            ? trim((string) $input->getOption('id'))
            : null;
        if ($input->getOption('generate-secret') === true) {
            $generated = $this->secrets->generate($id);
            $io->writeln('Webhook signing secret (shown once, copy it now): '.$generated);
            $io->success('Generated and saved the webhook signing secret in '.AlertSecrets::FILE.'.');

            return Command::SUCCESS;
        }
        $secret = (string) ($input->getOption('secret') ?? '');
        $this->secrets->set($secret, $id);
        $io->success('Saved the webhook signing secret in '.AlertSecrets::FILE.'.');

        return Command::SUCCESS;
    }

    private function unknown(SymfonyStyle $io): int
    {
        $io->error('Choose list, create, edit, delete or secret.');

        return Command::INVALID;
    }
}
