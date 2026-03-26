<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if already installed
        if ($this->userRepository->count([]) > 0) {
            $io->error('Application is already installed. Users already exist in the database.');
            return Command::FAILURE;
        }

        $io->title('Aggregate Analytics Installation');

        // Get admin credentials from config
        $adminUsername = $this->config->getWithEnvFallback('admin_username', null);
        $adminPassword = $this->config->getWithEnvFallback('admin_password', null);

        if (empty($adminUsername) || empty($adminPassword)) {
            $io->error([
                'Admin credentials not found in configuration.',
                'Please configure admin_username and admin_password in config/aggregate.yaml'
            ]);
            return Command::FAILURE;
        }

        // Create admin user
        $user = new User();
        $user->setUsername($adminUsername);
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $adminPassword);
        $user->setPassword($hashedPassword);

        $this->em->persist($user);
        $this->em->flush();

        $io->success([
            'Installation completed successfully!',
            sprintf('Admin user "%s" has been created.', $adminUsername),
            'You can now log in at /login'
        ]);

        return Command::SUCCESS;
    }
}
