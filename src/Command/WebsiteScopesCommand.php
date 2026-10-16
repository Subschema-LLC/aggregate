<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use App\Service\WebsiteScopePublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:website-scopes',
    description: 'Manage website groups and the word used for them',
)]
final class WebsiteScopesCommand extends Command
{
    public function __construct(
        private readonly WebsiteScopeCatalog $catalog,
        private readonly WebsiteConfigManager $websites,
        private readonly WebsiteScopePublisher $publisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'list, label, create-group, rename-group, delete-group, assign, or publish-model')
            ->addOption('singular', null, InputOption::VALUE_REQUIRED, 'Word for one group, such as Brand')
            ->addOption('plural', null, InputOption::VALUE_REQUIRED, 'Word for several groups, such as Brands')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Group name')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Group id')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Website token')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Group id, or an empty value to remove the website from its group')
            ->setHelp(<<<'HELP'
Website groups share collection settings and data models. A website setting overrides its group, and a group overrides the deployment. The word "group" can be renamed, for example to Brand.

  php bin/console app:website-scopes label --singular=Brand --plural=Brands
  php bin/console app:website-scopes create-group --name=Amazon
  php bin/console app:website-scopes assign --token=WEBSITE_TOKEN --group=amazon
  php bin/console app:website-scopes assign --token=WEBSITE_TOKEN --group=
  php bin/console app:website-scopes publish-model

Settings for a group live in config/website-scopes/groups/<id>.yaml. Settings for a website live in config/website-scopes/websites/<site-id>.yaml. The same pages in the dashboard edit those files.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        if (!is_string($action)) {
            $io->error('Choose an action.');

            return Command::INVALID;
        }
        try {
            return match ($action) {
                'list' => $this->list($io),
                'label' => $this->label($io, $input),
                'create-group' => $this->createGroup($io, $input),
                'rename-group' => $this->renameGroup($io, $input),
                'delete-group' => $this->deleteGroup($io, $input),
                'assign' => $this->assign($io, $input),
                'publish-model' => $this->publish($io),
                default => $this->unknown($io),
            };
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        } catch (\Throwable $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
    }

    private function list(SymfonyStyle $io): int
    {
        if ($this->catalog->hasLoadError()) {
            $io->error('config/website-scopes.yaml is invalid. Correct it before listing groups.');

            return Command::FAILURE;
        }
        $label = $this->catalog->label();
        $io->title($label['plural']);
        $io->writeln(sprintf('One %s is called a %s.', strtolower($label['singular']), $label['singular']));
        $names = [];
        foreach ($this->websites->getWebsites() as $site) {
            $token = (string) ($site['token'] ?? '');
            if ($token !== '') {
                $names[$token] = (string) ($site['name'] ?? 'Unnamed');
            }
        }
        $rows = [];
        foreach ($this->catalog->groups() as $id => $group) {
            $members = array_map(static fn (string $token): string => $names[$token] ?? 'Unknown website', $group['members']);
            $rows[] = [$id, $group['name'], $members === [] ? '—' : implode(', ', $members)];
        }
        if ($rows === []) {
            $io->note('No '.$label['plural'].' yet. Websites use the deployment settings.');

            return Command::SUCCESS;
        }
        $io->table(['Id', 'Name', 'Websites'], $rows);

        return Command::SUCCESS;
    }

    private function label(SymfonyStyle $io, InputInterface $input): int
    {
        $singular = $input->getOption('singular');
        $plural = $input->getOption('plural');
        if (!is_string($singular) || !is_string($plural)) {
            $io->error('Pass both --singular and --plural.');

            return Command::INVALID;
        }
        $this->catalog->setLabel($singular, $plural);
        $io->success(sprintf('Website groups are now called %s.', $plural));

        return Command::SUCCESS;
    }

    private function createGroup(SymfonyStyle $io, InputInterface $input): int
    {
        $name = $input->getOption('name');
        if (!is_string($name) || trim($name) === '') {
            $io->error('Pass --name.');

            return Command::INVALID;
        }
        $id = $this->catalog->createGroup($name);
        $io->success(sprintf('Created %s (%s).', $name, $id));

        return Command::SUCCESS;
    }

    private function renameGroup(SymfonyStyle $io, InputInterface $input): int
    {
        $id = $input->getOption('id');
        $name = $input->getOption('name');
        if (!is_string($id) || !is_string($name)) {
            $io->error('Pass --id and --name.');

            return Command::INVALID;
        }
        $this->catalog->renameGroup($id, $name);
        $io->success(sprintf('Renamed %s to %s.', $id, $name));

        return Command::SUCCESS;
    }

    private function deleteGroup(SymfonyStyle $io, InputInterface $input): int
    {
        $id = $input->getOption('id');
        if (!is_string($id)) {
            $io->error('Pass --id.');

            return Command::INVALID;
        }
        $this->catalog->deleteGroup($id);
        $io->success(sprintf('Deleted %s. Its websites now use the deployment settings unless they have their own.', $id));

        return Command::SUCCESS;
    }

    private function assign(SymfonyStyle $io, InputInterface $input): int
    {
        $token = $input->getOption('token');
        if (!is_string($token) || !$input->hasParameterOption('--group')) {
            $io->error('Pass --token and --group. Use --group= to remove the website from its group.');

            return Command::INVALID;
        }
        $group = $input->getOption('group');
        $groupId = is_string($group) && $group !== '' ? $group : null;
        $this->catalog->assign($token, $groupId);
        $io->success($groupId === null ? 'Website removed from its group.' : 'Website assigned.');

        return Command::SUCCESS;
    }

    private function publish(SymfonyStyle $io): int
    {
        $added = $this->publisher->publishModel();
        if ($added === []) {
            $io->success('The deployment model already has every column used by a website or group model.');

            return Command::SUCCESS;
        }
        $io->success('Published '.implode(', ', $added).' into the deployment data model. Regenerate reporting views if they are not already current.');

        return Command::SUCCESS;
    }

    private function unknown(SymfonyStyle $io): int
    {
        $io->error('Action must be list, label, create-group, rename-group, delete-group, assign, or publish-model.');

        return Command::INVALID;
    }
}
