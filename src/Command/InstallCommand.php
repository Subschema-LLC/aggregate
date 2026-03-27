<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:install',
    description: 'Install the application and create the first admin user'
)]
class InstallCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AggregateConfigLoader $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Admin username')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Admin password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if already installed
        if ($this->userRepository->count([]) > 0) {
            $io->error('Application is already installed. Users already exist in the database.');
            return Command::FAILURE;
        }

        $io->title('Aggregate Analytics Installation');

        $adminUsername = $input->getOption('username') ?: $this->config->getWithEnvFallback('admin_username', null);
        $adminPassword = $input->getOption('password') ?: $this->config->getWithEnvFallback('admin_password', null);

        if (empty($adminUsername) || empty($adminPassword)) {
            $helper = $this->getHelper('question');

            if (empty($adminUsername)) {
                $usernameQuestion = new Question('Admin username: ');
                $usernameQuestion->setValidator(static function (?string $value): string {
                    $username = trim((string) $value);
                    if ($username === '') {
                        throw new \RuntimeException('Username is required.');
                    }

                    return $username;
                });
                $adminUsername = $helper->ask($input, $output, $usernameQuestion);
            }

            if (empty($adminPassword)) {
                $passwordQuestion = new Question('Admin password (min 8 chars): ');
                $passwordQuestion->setHidden(true);
                $passwordQuestion->setValidator(static function (?string $value): string {
                    $password = (string) $value;
                    if (strlen($password) < 8) {
                        throw new \RuntimeException('Password must be at least 8 characters.');
                    }

                    return $password;
                });
                $adminPassword = $helper->ask($input, $output, $passwordQuestion);
            }
        }

        if (strlen((string) $adminPassword) < 8) {
            $io->error('Admin password must be at least 8 characters.');
            return Command::FAILURE;
        }

        if (empty($this->config->getWithEnvFallback('daily_salt_secret', null))) {
            $this->config->set('daily_salt_secret', base64_encode(random_bytes(32)));
        }

        // Create admin user
        $user = new User();
        $user->setUsername((string) $adminUsername);
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);

        $hashedPassword = $this->passwordHasher->hashPassword($user, (string) $adminPassword);
        $user->setPassword($hashedPassword);

        $this->em->persist($user);
        $this->em->flush();

        $this->config->set('installed', true);

        $io->success([
            'Admin setup completed successfully!',
            sprintf('Admin user "%s" has been created.', $adminUsername),
            'You can now log in at /login (if dashboard_enabled is true).',
        ]);

        return Command::SUCCESS;
    }
}
