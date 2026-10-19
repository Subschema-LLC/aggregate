<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReportingApi\ApiTokenStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api:token:revoke',
    description: 'Revoke a reporting API token',
)]
final class ApiTokenRevokeCommand extends Command
{
    public function __construct(private readonly ApiTokenStore $tokens)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The token ID, as app:api:token:list shows it')
            ->setHelp(<<<'HELP'
Deletes a reporting API token; requests with it fail from now on. Works while
the reporting API is turned off. Asks for confirmation unless run with
--no-interaction.

  php bin/console app:api:token:revoke 3f9a1c2e
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = strtolower(trim((string) $input->getArgument('id')));
        try {
            $token = $this->tokens->find($id);
        } catch (\RuntimeException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
        if ($token === null) {
            $io->error(sprintf('There is no API token with the ID "%s".', $id));

            return Command::INVALID;
        }
        if ($input->isInteractive() && !$io->confirm(sprintf('Revoke token %s ("%s")? Requests with it will fail.', $token->id, $token->name), false)) {
            $io->text('Nothing was revoked.');

            return Command::FAILURE;
        }
        try {
            $this->tokens->revoke($id);
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        } catch (\Throwable $error) {
            // The file could not be locked, read or written.
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf('Revoked API token %s ("%s").', $token->id, $token->name));

        return Command::SUCCESS;
    }
}
