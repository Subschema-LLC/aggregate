<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SiteScriptConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tag-manager:sites', description: 'List registered website script IDs and their YAML paths')]
final class ListScriptSitesCommand extends Command
{
    public function __construct(private readonly SiteScriptConfig $sites)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach ($this->sites->sites() as $site) {
            $rows[] = [$site['id'], OutputFormatter::escape($site['name']), OutputFormatter::escape($site['domain']), 'config/tag-manager/sites/'.$site['id'].'.yaml'];
        }
        $io->table(['Public script instance ID', 'Website', 'Domain', 'YAML configuration'], $rows);
        $io->text('Remote paths: /tms-lite/sites/<id>/lib.js and /cmp-lite/sites/<id>/consent.js. New instances default to disabled tags; no files were created.');

        return Command::SUCCESS;
    }
}
