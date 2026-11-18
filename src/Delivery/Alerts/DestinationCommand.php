<?php

declare(strict_types=1);

namespace App\Delivery\Alerts;

use App\Features\Destinations\DeleteDestination\DeleteDestination;
use App\Features\Destinations\SaveDestination\SaveDestination;
use App\Features\Destinations\SaveSigningSecret\SaveSigningSecret;
use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSecrets;
use App\Service\Alerts\AlertSettings;
use App\Service\FeatureFlags;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:alerts:destination',
    description: 'List, create, edit or delete alert destinations and webhook signing secrets',
    aliases: ['app:alerts:destinations'],
)]
final class DestinationCommand
{
    public function __construct(
        private readonly AlertSettings $settings,
        private readonly AlertSecrets $secrets,
        private readonly FeatureFlags $features,
        private readonly SaveDestination $saveDestination,
        private readonly DeleteDestination $deleteDestination,
        private readonly SaveSigningSecret $saveSecret,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('list, create, edit, delete or secret')]
        string $action,
        #[MapInput]
        DestinationOptions $options,
    ): int {
        $action = strtolower(trim($action));
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
                'create', 'add' => $this->save($io, $options, true),
                'edit', 'update' => $this->save($io, $options, false),
                'delete', 'remove' => $this->delete($io, $options),
                'secret' => $this->saveSecret($io, $options),
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
            $count = count($dest['recipients'] ?? []);
            $target = $dest['type'] === AlertRuleValidator::TYPE_WEBHOOK
                ? ($dest['host'] ?? '')
                : sprintf('%d listed recipient%s', $count, $count === 1 ? '' : 's');
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

    private function save(SymfonyStyle $io, DestinationOptions $options, bool $isNew): int
    {
        $id = $options->idOrNull();
        $existing = !$isNew && $id !== null ? $this->settings->destination($id) : null;
        $candidate = $options->candidate($existing);

        $secret = $options->secretChange();
        $result = $isNew
            ? $this->saveDestination->create($candidate, $secret)
            : $this->saveDestination->edit($candidate, $secret);
        $saved = $result['destination'];
        if ($result['generated_secret'] !== null) {
            $io->writeln('Webhook signing secret (shown once, copy it now): '
                .$result['generated_secret']);
        }

        $io->success(sprintf('Saved alert destination "%s" (%s).', $saved['label'], $saved['id']));

        return Command::SUCCESS;
    }

    private function delete(SymfonyStyle $io, DestinationOptions $options): int
    {
        $id = trim((string) ($options->id ?? ''));
        $this->deleteDestination->delete($id);
        $io->success(sprintf('Deleted alert destination "%s".', $id));

        return Command::SUCCESS;
    }

    private function saveSecret(SymfonyStyle $io, DestinationOptions $options): int
    {
        $id = $options->idOrNull();
        $which = $id === null
            ? 'the shared webhook signing secret'
            : sprintf('the signing secret of destination "%s"', $id);
        if ($id !== null && $options->useShared) {
            $this->saveSecret->remove($id);
            $signing = $this->secrets->describe($id);
            $io->success(sprintf('Destination "%s" now signs with: %s.', $id, $signing));

            return Command::SUCCESS;
        }
        if ($options->generateSecret) {
            $generated = $this->saveSecret->generate($id);
            $io->writeln('Webhook signing secret (shown once, copy it now): '.$generated);
            $io->success('Generated and saved '.$which.' in '.AlertSecrets::FILE.'.');
        } else {
            $secret = (string) ($options->secret ?? '');
            $this->saveSecret->set($secret, $id);
            $io->success('Saved '.$which.' in '.AlertSecrets::FILE.'.');
        }
        if ($id === null) {
            $io->note('Destinations with a secret of their own keep signing with it; '
                .'app:alerts:status shows which secret each one uses.');
        }

        return Command::SUCCESS;
    }

    private function unknown(SymfonyStyle $io): int
    {
        $io->error('Choose list, create, edit, delete or secret.');

        return Command::INVALID;
    }
}
