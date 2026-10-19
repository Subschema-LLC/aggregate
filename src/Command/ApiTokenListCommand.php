<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FeatureFlags;
use App\Service\ReportingApi\ApiTokenStore;
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
    name: 'app:api:token:list',
    description: 'List reporting API tokens and the API settings',
)]
final class ApiTokenListCommand extends Command
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly ApiTokenStore $tokens,
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
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the tokens as JSON')
            ->setHelp(<<<'HELP'
Lists the reporting API tokens: ID, name, the views each may read, and when it
expires. Tokens themselves are never shown; only their hashes are stored.

  php bin/console app:api:token:list
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $tokens = $this->tokens->tokens();
        } catch (\RuntimeException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
        $privateAllowed = $this->settings->privateViewsAllowed();
        $now = $this->clock->now();

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode(array_values(array_map(static fn ($token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'views' => $token->views,
                'private_views' => $token->privateViews,
                'created_at' => $token->createdAt->format(\DateTimeInterface::ATOM),
                'created_by' => $token->createdBy,
                'expires_at' => $token->expiresAt?->format(\DateTimeInterface::ATOM),
                'expired' => $token->isExpired($now),
                'mcp' => $token->mcp,
            ], $tokens)), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->definitionList(
            ['Reporting API' => $this->features->isEnabled('reporting_api') ? 'on' : 'off (feature flag reporting_api)'],
            ['MCP server' => $this->features->isEnabled('mcp_server') ? 'on' : 'off (feature flag mcp_server)'],
            ['Private views' => $privateAllowed ? 'allowed' : 'off ('.ReportingApiSettings::PRIVATE_VIEWS.')'],
        );
        if ($tokens === []) {
            $io->text('No API tokens. Create one with app:api:token:create.');

            return Command::SUCCESS;
        }
        $rows = [];
        foreach ($tokens as $token) {
            $private = $token->privateViews === [] ? '' : implode(', ', $token->privateViews).($privateAllowed ? '' : ' (off)');
            $rows[] = [
                $token->id,
                $token->name.($token->mcp ? ' [MCP]' : ''),
                $token->views === null ? 'every approved view' : implode(', ', $token->views),
                $private,
                $token->createdAt->format('Y-m-d').' by '.$token->createdBy,
                match (true) {
                    $token->expiresAt === null => 'never',
                    $token->isExpired($now) => 'expired '.$token->expiresAt->format('Y-m-d'),
                    default => $token->expiresAt->format('Y-m-d'),
                },
            ];
        }
        $io->table(['ID', 'Name', 'Views', 'Private views', 'Created', 'Expires'], $rows);

        return Command::SUCCESS;
    }
}
