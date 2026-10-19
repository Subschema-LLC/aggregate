<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\Access\AccessGrant;
use App\Security\Access\DashboardPages;
use App\Security\Access\PermissionGroupCatalog;
use App\Security\Access\UserAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:permission-groups',
    description: 'List, create, change or delete permission groups (roles) for standard users',
)]
final class PermissionGroupsCommand extends Command
{
    use AccessOptions;

    public function __construct(
        private readonly PermissionGroupCatalog $groups,
        private readonly UserAdministration $administration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'list, pages, create, update or delete')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Permission group id (update, delete)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Description')
            ->addOption('all-websites', null, InputOption::VALUE_NONE, 'Grant every website and website group, including future ones')
            ->addOption('website-group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Grant every website in a website group, by id (repeatable)')
            ->addOption('website', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Grant a website, by token (repeatable)')
            ->addOption('page', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Grant a page as PAGE:view or PAGE:edit (repeatable)')
            ->setHelp(<<<'HELP'
Permission groups are named sets of websites and pages assigned to standard
users. They live in config/permission-groups.yaml, which the Users page edits too.

  php bin/console app:permission-groups list
  php bin/console app:permission-groups pages
  php bin/console app:permission-groups create --name="Client editors" --website-group=brand-a --page=websites:view --page=tag_manager:edit
  php bin/console app:permission-groups update --id=client-editors --page=consent_manager:edit
  php bin/console app:permission-groups delete --id=client-editors

On update, --name and --description replace those values; website options
replace the websites, and --page options replace the pages. Assign groups to
users with app:user:access USERNAME --group=ID.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            return match ($input->getArgument('action')) {
                'list' => $this->list($io),
                'pages' => $this->pages($io),
                'create' => $this->create($io, $input),
                'update' => $this->update($io, $input),
                'delete' => $this->delete($io, $input),
                default => $this->unknown($io),
            };
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        } catch (\RuntimeException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
    }

    private function list(SymfonyStyle $io): int
    {
        if ($this->groups->hasLoadError()) {
            $io->error('config/'.PermissionGroupCatalog::FILE.' is invalid: '.$this->groups->loadError());

            return Command::FAILURE;
        }
        $groups = $this->groups->groups();
        if ($groups === []) {
            $io->text('No permission groups.');

            return Command::SUCCESS;
        }
        $members = $this->administration->permissionGroupMembers();
        $rows = [];
        foreach ($groups as $id => $group) {
            $websites = $group['websites']['all'] ? 'every website' : implode(', ', [
                ...array_map(static fn (string $id): string => 'group '.$id, $group['websites']['groups']),
                ...$group['websites']['sites'],
            ]);
            $pages = implode(', ', array_map(static fn (string $page, string $level): string => $page.':'.$level, array_keys($group['pages']), $group['pages']));
            $rows[] = [$id, $group['name'], $websites === '' ? 'none' : $websites, $pages === '' ? 'none' : $pages, (string) ($members[$id] ?? 0)];
        }
        $io->table(['Id', 'Name', 'Websites', 'Pages', 'Users'], $rows);

        return Command::SUCCESS;
    }

    private function pages(SymfonyStyle $io): int
    {
        $rows = [];
        foreach (DashboardPages::PAGES as $page => $definition) {
            $rows[] = [$page, $definition['label'], $definition['scope'], implode(', ', DashboardPages::levels($page))];
        }
        $io->table(['Page', 'Label', 'Scope', 'Levels'], $rows);
        $io->text('Website pages show only the granted websites; system pages show and change settings for every website.');

        return Command::SUCCESS;
    }

    private function create(SymfonyStyle $io, InputInterface $input): int
    {
        $id = $this->groups->create(
            (string) $input->getOption('name'),
            (string) ($input->getOption('description') ?? ''),
            $this->grant($input, AccessGrant::empty()),
        );
        $io->success(sprintf('Created permission group "%s".', $id));

        return Command::SUCCESS;
    }

    private function update(SymfonyStyle $io, InputInterface $input): int
    {
        $id = (string) $input->getOption('id');
        $group = $this->groups->group($id) ?? throw new \InvalidArgumentException(sprintf('Permission group "%s" does not exist.', $id));
        $this->groups->update(
            $id,
            is_string($input->getOption('name')) ? $input->getOption('name') : $group['name'],
            is_string($input->getOption('description')) ? $input->getOption('description') : $group['description'],
            $this->grant($input, $group),
        );
        $io->success(sprintf('Saved permission group "%s".', $id));

        return Command::SUCCESS;
    }

    private function delete(SymfonyStyle $io, InputInterface $input): int
    {
        $id = (string) $input->getOption('id');
        $this->groups->delete($id);
        $removed = $this->administration->forgetPermissionGroup($id);
        $io->success(sprintf('Deleted permission group "%s"%s.', $id, $removed > 0 ? sprintf(' and removed it from %d user(s)', $removed) : ''));

        return Command::SUCCESS;
    }

    /**
     * @param array{websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>} $current
     *
     * @return array{websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}
     */
    private function grant(InputInterface $input, array $current): array
    {
        $websites = $current['websites'];
        if ($input->getOption('all-websites') || $input->getOption('website-group') !== [] || $input->getOption('website') !== []) {
            $websites = [
                'all' => (bool) $input->getOption('all-websites'),
                'groups' => $input->getOption('website-group'),
                'sites' => $input->getOption('website'),
            ];
        }
        $pages = $input->getOption('page') !== [] ? self::applyPages([], self::pageOptions($input->getOption('page'))) : $current['pages'];

        return AccessGrant::normalize(['websites' => $websites, 'pages' => $pages]);
    }

    private function unknown(SymfonyStyle $io): int
    {
        $io->error('Choose list, pages, create, update or delete.');

        return Command::INVALID;
    }
}
