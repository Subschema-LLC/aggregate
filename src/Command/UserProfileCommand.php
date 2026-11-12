<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Access\UserAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * An account's name, display name and email address, as the Users page and
 * a user's own Profile change them, with the same checks. Access is changed
 * with app:user:access and the password with app:user:reset-password.
 */
#[AsCommand(
    name: 'app:user:profile',
    description: 'Show or change an account\'s username, display name and email address',
)]
final class UserProfileCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserAdministration $administration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The account to show or change')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'The name shown in the dashboard; empty to remove it')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'The address sign-in codes and alerts go to; empty to remove it')
            ->addOption('rename', null, InputOption::VALUE_REQUIRED, 'A new username')
            ->setHelp(<<<'HELP'
                  php bin/console app:user:profile alex
                  php bin/console app:user:profile alex --email=alex@example.com --display-name="Alex Kim"
                  php bin/console app:user:profile alex --rename=alexk

                Two-step sign-in sends codes to this address, so a user without one cannot sign in while it is required.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->users->findOneBy(['username' => (string) $input->getArgument('username')]);
        if (!$user instanceof User) {
            $io->error(sprintf('There is no user "%s".', (string) $input->getArgument('username')));

            return Command::FAILURE;
        }
        $displayName = $input->getOption('display-name');
        $email = $input->getOption('email');
        $rename = $input->getOption('rename');
        if ($displayName !== null || $email !== null || $rename !== null) {
            try {
                $this->administration->update(
                    $user,
                    is_string($rename) ? $rename : $user->getUsername(),
                    is_string($displayName) ? $displayName : $user->getDisplayName(),
                    is_string($email) ? $email : $user->getEmail(),
                    $user->isAdmin(),
                    $user->getAccess(),
                );
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success(sprintf('Saved "%s".', $user->getUsername()));
        }
        $io->definitionList(
            ['Username' => $user->getUsername()],
            ['Display name' => $user->getDisplayName() ?? '-'],
            ['Email' => $user->getEmail() ?? '-'],
            ['Administrator' => $user->isAdmin() ? 'yes' : 'no'],
        );

        return Command::SUCCESS;
    }
}
