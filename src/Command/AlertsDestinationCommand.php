<?php

declare(strict_types=1);

namespace App\Command;

use App\Features\DeleteDestination\DeleteDestination;
use App\Features\SaveDestination\SaveDestination;
use App\Features\SaveDestination\SecretChange;
use App\Features\SaveSigningSecret\SaveSigningSecret;
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
        private readonly SaveDestination $saveDestination,
        private readonly DeleteDestination $deleteDestination,
        private readonly SaveSigningSecret $saveSecret,
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
            ->addOption('generate-secret', null, InputOption::VALUE_NONE, 'Generate and store a new random webhook signing secret')
            ->addOption('use-shared', null, InputOption::VALUE_NONE, 'With secret --id: remove the destination\'s own secret so it signs with the shared one');
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
                'create', 'add' => $this->save($io, $input, true),
                'edit', 'update' => $this->save($io, $input, false),
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

    private function save(SymfonyStyle $io, InputInterface $input, bool $isNew): int
    {
        $id = $input->getOption('id') !== null ? trim((string) $input->getOption('id')) : null;
        $existing = !$isNew && $id !== null && $id !== '' ? $this->settings->destination($id) : null;

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

        $secret = $input->getOption('generate-secret') === true
            ? SecretChange::generate()
            : SecretChange::set((string) ($input->getOption('secret') ?? ''));
        $result = $isNew
            ? $this->saveDestination->create($candidate, $secret)
            : $this->saveDestination->edit($candidate, $secret);
        $saved = $result['destination'];
        if ($result['generated_secret'] !== null) {
            $io->writeln('Webhook signing secret (shown once, copy it now): '.$result['generated_secret']);
        }

        $io->success(sprintf('Saved alert destination "%s" (%s).', $saved['label'], $saved['id']));

        return Command::SUCCESS;
    }

    private function delete(SymfonyStyle $io, InputInterface $input): int
    {
        $id = trim((string) ($input->getOption('id') ?? ''));
        $this->deleteDestination->delete($id);
        $io->success(sprintf('Deleted alert destination "%s".', $id));

        return Command::SUCCESS;
    }

    private function saveSecret(SymfonyStyle $io, InputInterface $input): int
    {
        $id = $input->getOption('id') !== null && trim((string) $input->getOption('id')) !== ''
            ? trim((string) $input->getOption('id'))
            : null;
        $which = $id === null ? 'the shared webhook signing secret' : sprintf('the signing secret of destination "%s"', $id);
        if ($id !== null && $input->getOption('use-shared') === true) {
            $this->saveSecret->remove($id);
            $io->success(sprintf('Destination "%s" now signs with: %s.', $id, $this->secrets->describe($id)));

            return Command::SUCCESS;
        }
        if ($input->getOption('generate-secret') === true) {
            $generated = $this->saveSecret->generate($id);
            $io->writeln('Webhook signing secret (shown once, copy it now): '.$generated);
            $io->success('Generated and saved '.$which.' in '.AlertSecrets::FILE.'.');
        } else {
            $secret = (string) ($input->getOption('secret') ?? '');
            $this->saveSecret->set($secret, $id);
            $io->success('Saved '.$which.' in '.AlertSecrets::FILE.'.');
        }
        if ($id === null) {
            $io->note('Destinations with a secret of their own keep signing with it; app:alerts:status shows which secret each one uses.');
        }

        return Command::SUCCESS;
    }

    private function unknown(SymfonyStyle $io): int
    {
        $io->error('Choose list, create, edit, delete or secret.');

        return Command::INVALID;
    }
}
