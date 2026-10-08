<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
    name: 'app:user:create',
    description: 'Create a new user account',
)]
class CreateUserCommand extends Command
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
            ->addArgument('username', InputArgument::OPTIONAL, 'Username for the new account')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Password for the new account (minimum 8 characters)')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Role for the new account (repeat --role for multiple roles)')
            ->setHelp(
                "This command creates a new user account.\n\n"
                ."Usage examples:\n"
                ."  php bin/console app:user:create analyst --password='secure-password' --role=ROLE_ANALYST\n"
                ."  php bin/console app:user:create manager --password='secure-password' --role=ROLE_MANAGER --role=ROLE_EDITOR"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $this->resolveUsername($input, $output, $io);
        if ($username === null) {
            return Command::FAILURE;
        }

        $password = $this->resolvePassword($input, $io);
        if ($password === null) {
            return Command::FAILURE;
        }

        $roles = $this->resolveRoles($input, $output, $io);
        if ($roles === null) {
            return Command::FAILURE;
        }

        while (true) {
            $user = new User();
            $user->setUsername($username);
            $user->setRoles($roles);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));

            try {
                $this->em->persist($user);
                $this->em->flush();
                break;
            } catch (UniqueConstraintViolationException) {
                if (!$input->isInteractive()) {
                    $io->error(sprintf('User "%s" already exists.', $username));
                    return Command::FAILURE;
                }

                $io->warning(sprintf('User "%s" already exists. Please choose a different username.', $username));
                $username = $this->promptForUsername($input, $output, $io);
            }
        }

        $io->success(sprintf(
            'User "%s" created successfully with role%s: %s.',
            $username,
            count($roles) === 1 ? '' : 's',
            implode(', ', $roles),
        ));

        return Command::SUCCESS;
    }

    private function resolveUsername(InputInterface $input, OutputInterface $output, SymfonyStyle $io): ?string
    {
        $username = trim((string) $input->getArgument('username'));

        if ($username === '') {
            if (!$input->isInteractive()) {
                $io->error('Username argument is required in non-interactive mode.');
                return null;
            }

            $username = $this->promptForUsername($input, $output, $io);
        } else {
            if (strlen($username) > 180) {
                $io->error('Username must be 180 characters or fewer.');
                return null;
            }

            while ($this->userRepository->findOneBy(['username' => $username]) instanceof User) {
                if (!$input->isInteractive()) {
                    $io->error(sprintf('User "%s" already exists.', $username));
                    return null;
                }

                $io->warning(sprintf('User "%s" already exists. Please choose a different username.', $username));
                $username = $this->promptForUsername($input, $output, $io);
            }
        }

        return $username;
    }

    private function promptForUsername(InputInterface $input, OutputInterface $output, SymfonyStyle $io): string
    {
        $helper = $this->getHelper('question');
        $usernameQuestion = new Question('Username for the new account: ');
        $usernameQuestion->setValidator(function (?string $value): string {
            $username = trim((string) $value);
            if ($username === '') {
                throw new \RuntimeException('Username cannot be empty.');
            }
            if (strlen($username) > 180) {
                throw new \RuntimeException('Username must be 180 characters or fewer.');
            }
            if ($this->userRepository->findOneBy(['username' => $username]) instanceof User) {
                throw new \RuntimeException(sprintf('User "%s" already exists. Enter a different username.', $username));
            }

            return $username;
        });

        /** @var string $username */
        $username = $helper->ask($input, $output, $usernameQuestion);
        return $username;
    }

    private function resolvePassword(InputInterface $input, SymfonyStyle $io): ?string
    {
        $password = (string) ($input->getOption('password') ?? '');
        if ($password === '') {
            if (!$input->isInteractive()) {
                $io->error('The --password option is required in non-interactive mode.');
                return null;
            }

            $passwordQuestion = new Question('Password (min 8 characters): ');
            $passwordQuestion->setHidden(true);
            $passwordQuestion->setValidator(static function (?string $value): string {
                $password = (string) $value;
                if (strlen($password) < 8) {
                    throw new \RuntimeException('Password must be at least 8 characters.');
                }

                return $password;
            });
            $password = $io->askQuestion($passwordQuestion);
        }

        if (strlen($password) < 8) {
            $io->error('Password must be at least 8 characters.');
            return null;
        }

        return $password;
    }

    /**
     * @return list<string>|null
     */
    private function resolveRoles(InputInterface $input, OutputInterface $output, SymfonyStyle $io): ?array
    {
        $roleInputs = $input->getOption('role');
        $roleInputs = is_array($roleInputs) ? $roleInputs : [];
        $roles = $this->normalizeRoles($roleInputs);

        if ($roles === []) {
            if (!$input->isInteractive()) {
                $io->error('At least one --role option is required in non-interactive mode.');
                return null;
            }

            $helper = $this->getHelper('question');
            $roleQuestion = new Question('Role (for example ROLE_ADMIN): ');
            $roleQuestion->setValidator(static function (?string $value): string {
                $role = trim((string) $value);
                if ($role === '') {
                    throw new \RuntimeException('Role cannot be empty.');
                }

                return $role;
            });
            $role = $helper->ask($input, $output, $roleQuestion);
            $roles = [$role];
        }

        return $roles;
    }

    /**
     * @param list<mixed> $roleInputs
     * @return list<string>
     */
    private function normalizeRoles(array $roleInputs): array
    {
        $roles = [];
        foreach ($roleInputs as $roleInput) {
            $role = trim((string) $roleInput);
            if ($role !== '') {
                $roles[$role] = true;
            }
        }

        return array_keys($roles);
    }
}

