<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Email\EmailPurpose;
use App\Service\Email\EmailSecrets;
use App\Service\Email\EmailSettings;
use App\Service\Email\TransactionalMailer;
use App\Service\Operations\ProcessingTasks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:email:status',
    description: 'Show the email service, the sender, which credentials are set (never their values) and the last sends',
)]
final class EmailStatusCommand extends Command
{
    public function __construct(
        private readonly EmailSettings $settings,
        private readonly EmailSecrets $secrets,
        private readonly TransactionalMailer $mailer,
        private readonly ProcessingTasks $tasks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the status as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $settings = $this->settings->toArray();
        } catch (\Throwable $e) {
            $io->error('The email settings are invalid'.($e instanceof \InvalidArgumentException ? ': '.$e->getMessage() : '.'));

            return Command::FAILURE;
        }
        $problem = $this->mailer->problem($settings);
        $credentials = [];
        foreach ($this->settings->providers()->all() as $code => $provider) {
            $credentials[$code] = ['credential' => $provider->credential()->key, 'source' => $this->secrets->source($provider->credential())];
        }
        $sends = [];
        try {
            $latest = $this->tasks->latest(TransactionalMailer::TASK_TYPE);
        } catch (\Throwable $e) {
            $io->warning('The last sends could not be read from processing_tasks ('.$e::class.'). Run the database migrations.');
            $latest = [];
        }
        foreach (EmailPurpose::cases() as $purpose) {
            $run = $latest[$purpose->value] ?? null;
            $sends[$purpose->value] = $run === null ? null : [
                'last_attempt' => $run['attempt']['started_at']->format(\DATE_ATOM),
                'status' => $run['attempt']['status'],
                'message_id' => $run['attempt']['external_id'],
                'details' => $run['attempt']['details'],
                'last_sent' => $run['success'] === null ? null : $run['success']['started_at']->format(\DATE_ATOM),
            ];
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'enabled' => $settings[EmailSettings::KEY_ENABLED],
                'provider' => $settings[EmailSettings::KEY_PROVIDER] === '' ? null : $settings[EmailSettings::KEY_PROVIDER],
                'from_address' => $settings[EmailSettings::KEY_FROM_ADDRESS] === '' ? null : $settings[EmailSettings::KEY_FROM_ADDRESS],
                'ready' => $problem === null,
                'problem' => $problem,
                'credentials' => $credentials,
                'sends' => $sends,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $problem === null ? Command::SUCCESS : Command::FAILURE;
        }

        $code = $settings[EmailSettings::KEY_PROVIDER];
        if ($problem === null) {
            $io->success(sprintf('Email is on and sends through %s from %s.', $this->settings->providers()->get($code)->label(), $settings[EmailSettings::KEY_FROM_ADDRESS]));
        } else {
            $io->warning('Nothing can be sent: '.$problem);
        }
        $io->table(['Service', 'Credential', 'Set'], array_map(fn (string $code, array $state): array => [
            $this->settings->providers()->get($code)->label().($code === $settings[EmailSettings::KEY_PROVIDER] ? ' (chosen)' : ''),
            $state['credential'],
            match ($state['source']) {
                EmailSecrets::SOURCE_ENVIRONMENT => 'in the environment',
                EmailSecrets::SOURCE_FILE => 'in '.EmailSecrets::FILE,
                default => 'no',
            },
        ], array_keys($credentials), $credentials));
        $rows = [];
        foreach (EmailPurpose::cases() as $purpose) {
            $send = $sends[$purpose->value];
            $rows[] = [
                $purpose->label(),
                $send === null ? 'never' : $send['last_attempt'].' ('.$send['status'].')',
                $send === null ? '' : ($send['status'] === 'succeeded' ? (string) $send['message_id'] : (string) $send['details']),
            ];
        }
        $io->table(['Message', 'Last attempt', 'Message ID or reason'], $rows);

        return $problem === null ? Command::SUCCESS : Command::FAILURE;
    }
}
