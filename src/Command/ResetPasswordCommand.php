<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Reset the password for a user account without requiring email',
    aliases: ['app:reset-password'],
)]
class ResetPasswordCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::OPTIONAL, 'Username of the account to reset')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'New password (minimum 8 characters)')
            ->setHelp(
                "This command resets a user's password without requiring an email address.\n\n"
                ."Usage examples:\n"
                ."  php bin/console app:user:reset-password admin\n"
                ."  php bin/console app:user:reset-password admin --password=NewSecurePassword123\n"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->userRepository->count([]) === 0) {
            $io->error('No users found in the database. Run "php bin/console app:install" to create an administrator.');
            return Command::FAILURE;
        }

        $username = $input->getArgument('username');
        if (empty($username)) {
            if (!$input->isInteractive()) {
                $io->error('Username argument is required in non-interactive mode.');
                return Command::FAILURE;
            }

            $usernameQuestion = new Question('Username of the account to reset: ');
            $usernameQuestion->setValidator(static function (?string $value): string {
                $trimmed = trim((string) $value);
                if ($trimmed === '') {
                    throw new \RuntimeException('Username cannot be empty.');
                }
                return $trimmed;
            });
            $username = $io->askQuestion($usernameQuestion);
        }

        $user = $this->userRepository->findOneBy(['username' => $username]);
        if (!$user instanceof User) {
            $io->error(sprintf('User "%s" not found.', $username));
            return Command::FAILURE;
        }

        $password = $input->getOption('password');
        if (empty($password)) {
            if (!$input->isInteractive()) {
                $io->error('The --password option is required in non-interactive mode.');
                return Command::FAILURE;
            }

            $passwordQuestion = new Question('New password (min 8 characters): ');
            $passwordQuestion->setHidden(true);
            $passwordQuestion->setValidator(static function (?string $value): string {
                $trimmed = (string) $value;
                if (strlen($trimmed) < 8) {
                    throw new \RuntimeException('Password must be at least 8 characters.');
                }
                return $trimmed;
            });
            $password = $io->askQuestion($passwordQuestion);
        }

        if (strlen((string) $password) < 8) {
            $io->error('Password must be at least 8 characters.');
            return Command::FAILURE;
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, (string) $password);
        $user->setPassword($hashedPassword);

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Password for user "%s" has been reset successfully.', $user->getUsername()));

        return Command::SUCCESS;
    }
}
