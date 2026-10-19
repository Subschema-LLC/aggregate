<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReportingApi\ApiTokenStore;
use App\Service\ReportingApi\ReportingApiSettings;
use App\Service\ViewSync\ViewCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api:token:create',
    description: 'Create a reporting API token, optionally for AI assistants, and print it once',
)]
final class ApiTokenCreateCommand extends Command
{
    public function __construct(
        private readonly ApiTokenStore $tokens,
        private readonly ReportingApiSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'What the token is for, such as "Power BI refresh"')
            ->addOption('view', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'An approved view the token may read; repeat for more. Default: every approved view, including ones added later')
            ->addOption('private-view', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A private (row-level or unsuppressed) view the token may read; repeat for more. Needs reporting_api_private_views: true')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'When the token stops working: a duration (30d, 12w, 6m, 1y), a date (YYYY-MM-DD) or "never"', ApiTokenStore::DEFAULT_EXPIRY)
            ->addOption('created-by', null, InputOption::VALUE_REQUIRED, 'Who the token is recorded as created by', 'command line')
            ->addOption('mcp', null, InputOption::VALUE_NONE, 'Let AI assistants use the token on the MCP server too. Needs the mcp_server feature flag on')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the token and its details as JSON')
            ->setHelp(<<<'HELP'
Creates a token for the read-only reporting API and prints it once. Only its
hash is stored in config/secrets/api-tokens.yaml, so copy it now: a lost token
can be revoked and replaced, never shown again. Needs the reporting_api or
the mcp_server feature flag on. Labels and definitions (the bi_dim_* and
glossary views) can be read with any token.

  php bin/console app:api:token:create "Power BI refresh"
  php bin/console app:api:token:create "Notebook" --view=bi_anonymous_daily_v1 --expires=90d
  php bin/console app:api:token:create "Raw export" --view=bi_anonymous_daily_v1 --private-view=analytics_custom_events_v1
  php bin/console app:api:token:create "Gemini CLI" --mcp --expires=90d

--mcp lets AI assistants that take a header use the token on the MCP server
(docs/MCP.md). Assistants that sign in, such as Claude and ChatGPT, get their
own connection instead.

Private views hold individual events or unsuppressed counts. Grant them only
for an approved raw-data workflow; see docs/REPORTING-API.md#private-views.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $views */
        $views = $input->getOption('view');
        /** @var list<string> $privateViews */
        $privateViews = $input->getOption('private-view');
        try {
            $expiresAt = $this->tokens->expiryFrom((string) $input->getOption('expires'));
            [$token, $secret] = $this->tokens->create((string) $input->getArgument('name'), $views === [] ? null : $views, $privateViews, $expiresAt, (string) $input->getOption('created-by'), $input->getOption('mcp') === true);
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        } catch (\RuntimeException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode([
                'id' => $token->id,
                'name' => $token->name,
                'token' => $secret,
                'views' => $token->views,
                'private_views' => $token->privateViews,
                'expires_at' => $token->expiresAt?->format(\DateTimeInterface::ATOM),
                'mcp' => $token->mcp,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Created API token %s ("%s").', $token->id, $token->name));
        $io->writeln('Copy the token now. It is not stored and cannot be shown again:');
        $io->newLine();
        $io->writeln('  '.$secret);
        $io->newLine();
        $io->definitionList(
            ['Views' => $token->views === null ? 'every approved view' : implode(', ', $token->views)],
            ['Private views' => $token->privateViews === [] ? 'none' : implode(', ', $token->privateViews)],
            ['Expires' => $token->expiresAt === null ? 'never' : $token->expiresAt->format('Y-m-d H:i').' UTC'],
            ['AI assistants (MCP)' => $token->mcp ? 'allowed' : 'no'],
        );
        if ($token->expiresAt === null) {
            $io->warning(ApiTokenStore::NEVER_EXPIRES_WARNING);
        }
        if ($token->privateViews !== []) {
            $io->warning('This token can read row-level or unsuppressed data ('.implode(', ', $token->privateViews).'). Keep it to the approved raw-data workflow it was created for.');
        }
        $example = $token->views === null ? array_key_first(ViewCatalog::APPROVED) : ($token->views[0] ?? $token->privateViews[0]);
        $io->writeln('Try it:');
        $io->writeln(sprintf('  curl -H "Authorization: Bearer $AGGREGATE_API_TOKEN" "https://YOUR-HOST/api/v1/views/%s?limit=10"', $example));
        if ($token->mcp) {
            $io->writeln('For an AI assistant, add the remote MCP server https://YOUR-HOST/mcp with the header "Authorization: Bearer <token>".');
        }
        if (!$this->settings->privateViewsAllowed() && $token->privateViews !== []) {
            $io->note('Private views are turned off, so this token cannot read them yet.');
        }

        return Command::SUCCESS;
    }
}
