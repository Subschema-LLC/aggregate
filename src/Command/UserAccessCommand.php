<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Access\UserAccessResolver;
use App\Security\Access\UserAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:access',
    description: 'Show or change what a user can use: administrator, permission groups, websites and pages',
)]
final class UserAccessCommand extends Command
{
    use AccessOptions;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserAdministration $administration,
        private readonly UserAccessResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The account to show or change')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Make the account an administrator')
            ->addOption('standard', null, InputOption::VALUE_NONE, 'Make the account a standard user')
            ->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Assign a permission group by id (repeatable)')
            ->addOption('remove-group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Remove a permission group by id (repeatable)')
            ->addOption('all-websites', null, InputOption::VALUE_NONE, 'Grant every website and website group, including future ones')
            ->addOption('selected-websites', null, InputOption::VALUE_NONE, 'Stop granting every website; keep the listed ones')
            ->addOption('website-group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Grant every website in a website group, by id (repeatable)')
            ->addOption('website', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Grant a website, by token (repeatable)')
            ->addOption('page', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Grant a page as PAGE:view, PAGE:edit, or remove it with PAGE:none (repeatable)')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Remove the account\'s permission groups and direct grants first')
            ->setHelp(<<<'HELP'
Without options, shows what the account can use and where each grant comes from.
Options add to the account's own grants; --clear starts from nothing.

  php bin/console app:user:access alex
  php bin/console app:user:access alex --group=client-editors
  php bin/console app:user:access alex --website-group=brand-a --page=websites:view --page=tag_manager:edit
  php bin/console app:user:access alex --clear --all-websites --page=data_model:view
  php bin/console app:user:access alex --admin

Pages, levels and permission groups are described in docs/USERS-AND-PERMISSIONS.md.
An installation always keeps at least one administrator.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->users->findOneBy(['username' => (string) $input->getArgument('username')]);
        if (!$user instanceof User) {
            $io->error(sprintf('User "%s" does not exist.', (string) $input->getArgument('username')));

            return Command::FAILURE;
        }
        if ($input->getOption('admin') && $input->getOption('standard')) {
            $io->error('Choose either --admin or --standard.');

            return Command::INVALID;
        }
        if ($input->getOption('all-websites') && $input->getOption('selected-websites')) {
            $io->error('Choose either --all-websites or --selected-websites.');

            return Command::INVALID;
        }

        try {
            if ($this->changes($input)) {
                $access = $input->getOption('clear') ? ['permission_groups' => [], 'websites' => ['all' => false, 'groups' => [], 'sites' => []], 'pages' => []] : $user->getAccess();
                $access['permission_groups'] = array_values(array_diff(
                    array_unique([...$access['permission_groups'], ...$input->getOption('group')]),
                    $input->getOption('remove-group'),
                ));
                if ($input->getOption('all-websites')) {
                    $access['websites']['all'] = true;
                } elseif ($input->getOption('selected-websites')) {
                    $access['websites']['all'] = false;
                }
                $access['websites']['groups'] = array_values(array_unique([...$access['websites']['groups'], ...$input->getOption('website-group')]));
                $access['websites']['sites'] = array_values(array_unique([...$access['websites']['sites'], ...$input->getOption('website')]));
                $access['pages'] = self::applyPages($access['pages'], self::pageOptions($input->getOption('page')));
                $admin = $input->getOption('admin') ? true : ($input->getOption('standard') ? false : $user->isAdmin());
                $this->administration->update($user, $user->getUsername(), $user->getDisplayName(), $user->getEmail(), $admin, $access);
                $this->resolver->reset();
                $io->success(sprintf('Saved access for "%s".', $user->getUsername()));
            }
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        }

        $io->section(sprintf('%s (%s)', $user->getLabel(), $user->isAdmin() ? 'administrator' : 'standard user'));
        if (!$user->isAdmin()) {
            $groups = $user->getAccess()['permission_groups'];
            $io->text('Permission groups: '.($groups === [] ? 'none' : implode(', ', $groups)));
        }
        self::printSummary($io, $this->resolver->forUser($user)->summary());

        return Command::SUCCESS;
    }

    private function changes(InputInterface $input): bool
    {
        foreach (['admin', 'standard', 'all-websites', 'selected-websites', 'clear'] as $flag) {
            if ($input->getOption($flag)) {
                return true;
            }
        }
        foreach (['group', 'remove-group', 'website-group', 'website', 'page'] as $list) {
            if ($input->getOption($list) !== []) {
                return true;
            }
        }

        return false;
    }
}
