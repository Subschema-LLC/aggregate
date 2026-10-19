<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Email\EmailPurpose;
use App\Service\Email\TransactionalMailer;
use App\Service\Operations\TaskTrigger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:email:test',
    description: 'Send a test email with the saved settings, even while email is off',
)]
final class EmailTestCommand extends Command
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('recipient', InputArgument::REQUIRED, 'An email address, or the username of a user with one')
            ->setHelp(
                "Sends a short test message through the configured email service and prints the\n"
                ."service's message ID. The send is recorded as a processing task and in the audit\n"
                ."trail, without the address.\n\n"
                ."  php bin/console app:email:test you@example.com\n"
                ."  php bin/console app:email:test admin\n"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recipient = trim((string) $input->getArgument('recipient'));
        if (!str_contains($recipient, '@')) {
            $address = $this->users->findOneBy(['username' => $recipient])?->getEmail();
            if ($address === null) {
                $io->error('No user with that username has an email address. Give an address instead.');

                return Command::FAILURE;
            }
            $recipient = $address;
        } elseif (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $io->error('That is not an email address.');

            return Command::FAILURE;
        }

        $result = $this->mailer->send(EmailPurpose::Test, $recipient, [], TaskTrigger::command());
        if (!$result->sent) {
            $io->error((string) $result->error);

            return Command::FAILURE;
        }
        $io->success('The test email was sent.'.($result->messageId !== null ? ' The service’s message ID is '.$result->messageId.'.' : ''));

        return Command::SUCCESS;
    }
}
