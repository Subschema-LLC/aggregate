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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:delete',
    description: 'Delete a user account',
)]
final class UserDeleteCommand extends Command
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
            ->addArgument('username', InputArgument::REQUIRED, 'The account to delete')
            ->setHelp(<<<'HELP'
Deletes an account; it can no longer sign in. The last administrator cannot be deleted.
Asks for confirmation unless run with --no-interaction.

  php bin/console app:user:delete alex
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');
        $user = $this->users->findOneBy(['username' => $username]);
        if (!$user instanceof User) {
            $io->error(sprintf('User "%s" does not exist.', $username));

            return Command::FAILURE;
        }
        if ($input->isInteractive() && !$io->confirm(sprintf('Delete "%s"? They can no longer sign in.', $username), false)) {
            $io->text('Nothing was deleted.');

            // Not a success: the audit trail records the deletion as not done.
            return Command::FAILURE;
        }
        try {
            $this->administration->delete($user);
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        }
        $io->success(sprintf('Deleted "%s".', $username));

        return Command::SUCCESS;
    }
}
