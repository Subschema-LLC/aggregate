<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BrowserAssetBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:assets:build-js', description: 'Minify browser scripts using the installed, pinned Terser build')]
final class BuildBrowserAssetsCommand extends Command
{
    public function __construct(private readonly BrowserAssetBuilder $builder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('Runs the same optional build as Setup → Build minified scripts. Requires Node.js 18+ and the pinned Terser dependency on the build host. Does not install dependencies or change YAML. Works with the dashboard disabled. Copy generated assets to prepared releases; script serving does not require Node.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $this->builder->build();
        } catch (\RuntimeException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
        $io->success('Browser scripts were minified. Configured endpoints use current YAML settings with ?min=1.');

        return Command::SUCCESS;
    }
}
