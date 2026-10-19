<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Mcp\OAuth\OAuthStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mcp:connection:revoke',
    description: 'Disconnect an AI assistant connected through sign-in',
)]
final class McpConnectionRevokeCommand extends Command
{
    public function __construct(private readonly OAuthStore $connections)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The connection ID, as app:mcp:connection:list shows it')
            ->setHelp(<<<'HELP'
Deletes an assistant connection; its access and refresh tokens fail from now
on, and the assistant has to sign in again to reconnect. Works while the MCP
server is turned off. Asks for confirmation unless run with --no-interaction.

  php bin/console app:mcp:connection:revoke 8b7c6d5e
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = strtolower(trim((string) $input->getArgument('id')));
        try {
            $connection = $this->connections->findConnection($id);
        } catch (\RuntimeException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
        if ($connection === null) {
            $io->error(sprintf('There is no assistant connection with the ID "%s".', $id));

            return Command::INVALID;
        }
        if ($input->isInteractive() && !$io->confirm(sprintf('Disconnect %s (%s)? Its requests will fail.', $connection->clientName, $connection->id), false)) {
            $io->text('Nothing was revoked.');

            return Command::FAILURE;
        }
        try {
            $this->connections->revoke($id);
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        } catch (\Throwable $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf('Disconnected %s (%s).', $connection->clientName, $connection->id));

        return Command::SUCCESS;
    }
}
