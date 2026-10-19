<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FeatureFlags;
use App\Service\Mcp\McpConnection;
use App\Service\Mcp\OAuth\OAuthStore;
use App\Service\ReportingApi\ReportingApiSettings;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mcp:connection:list',
    description: 'List AI assistants connected to the MCP server through sign-in',
)]
final class McpConnectionListCommand extends Command
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly OAuthStore $connections,
        private readonly ReportingApiSettings $settings,
        private readonly FeatureFlags $features,
        ?ClockInterface $clock = null,
    ) {
        parent::__construct();
        $this->clock = $clock ?? Clock::get();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the connections as JSON')
            ->setHelp(<<<'HELP'
Lists the AI assistants an administrator approved through sign-in: ID,
assistant, the views each may read, who approved it and when it expires.
Tokens are never shown; only their hashes are stored, in
config/secrets/mcp-oauth.yaml. Tokens created with app:api:token:create --mcp
are listed by app:api:token:list.

  php bin/console app:mcp:connection:list
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $connections = $this->connections->connections();
        } catch (\RuntimeException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
        $privateAllowed = $this->settings->privateViewsAllowed();
        $now = $this->clock->now();

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode(array_values(array_map(static fn (McpConnection $connection): array => [
                'id' => $connection->id,
                'assistant' => $connection->clientName,
                'client_id' => $connection->clientId,
                'views' => $connection->views,
                'private_views' => $connection->privateViews,
                'approved_by' => $connection->approvedBy,
                'created_at' => $connection->createdAt->format(\DateTimeInterface::ATOM),
                'expires_at' => $connection->expiresAt?->format(\DateTimeInterface::ATOM),
                'expired' => $connection->isExpired($now),
            ], $connections)), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->definitionList(
            ['MCP server' => $this->features->isEnabled('mcp_server') ? 'on' : 'off (feature flag mcp_server)'],
            ['Private views' => $privateAllowed ? 'allowed' : 'off ('.ReportingApiSettings::PRIVATE_VIEWS.')'],
        );
        if ($connections === []) {
            $io->text('No assistants are connected through sign-in.');

            return Command::SUCCESS;
        }
        $rows = [];
        foreach ($connections as $connection) {
            $rows[] = [
                $connection->id,
                $connection->clientName.' ('.$connection->clientHost().')',
                ($connection->views === null ? 'every approved view' : implode(', ', $connection->views))
                    .($connection->privateViews === [] ? '' : '; private: '.implode(', ', $connection->privateViews).($privateAllowed ? '' : ' (off)')),
                $connection->createdAt->format('Y-m-d').' by '.$connection->approvedBy,
                match (true) {
                    $connection->expiresAt === null => 'never',
                    $connection->isExpired($now) => 'expired '.$connection->expiresAt->format('Y-m-d'),
                    default => $connection->expiresAt->format('Y-m-d'),
                },
            ];
        }
        $io->table(['ID', 'Assistant', 'Views', 'Approved', 'Expires'], $rows);

        return Command::SUCCESS;
    }
}
