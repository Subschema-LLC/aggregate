<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WebsiteActivityService;
use App\Service\WebsiteConfigManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: "app:websites:check-activity",
    description: "Check data reception status and last received event for registered websites",
)]
final class CheckWebsiteActivityCommand extends Command
{
    public function __construct(
        private readonly WebsiteActivityService $activityService,
        private readonly WebsiteConfigManager $websiteManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            "refresh",
            "r",
            InputOption::VALUE_NONE,
            "Bypass and refresh the cached activity statuses",
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $refresh = (bool) $input->getOption("refresh");

        $activeDays = $this->activityService->getActiveDays();
        $staleDays = $this->activityService->getStaleDays();

        $io->title("Website Activity Check");
        $io->comment(sprintf("Thresholds: Active <= %d day(s), Idle <= %d day(s), Inactive > %d day(s)", $activeDays, $staleDays, $staleDays));

        $statuses = $this->activityService->getStatuses($refresh);
        $websites = $this->websiteManager->getWebsites();

        if ($websites === []) {
            $io->warning("No websites are configured in config/websites.yaml.");
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($websites as $site) {
            $token = (string) ($site["token"] ?? "");
            $name = (string) ($site["name"] ?? "Unnamed");
            $domain = (string) ($site["domain"] ?? "");
            $info = $statuses[$token] ?? null;

            $status = $info["status"] ?? WebsiteActivityService::STATUS_NONE;
            $relative = $info["relative_time"] ?? "Never";
            $lastFormatted = $info["last_event_at_formatted"] ?? "Never";

            $statusBadge = match ($status) {
                WebsiteActivityService::STATUS_ACTIVE => "<info>● Active</info>",
                WebsiteActivityService::STATUS_IDLE => "<comment>○ Idle</comment>",
                WebsiteActivityService::STATUS_INACTIVE => "<fg=gray>◌ Inactive</fg=gray>",
                default => "<fg=yellow>◌ Waiting</fg=yellow>",
            };

            $rows[] = [
                $name,
                $domain,
                $token,
                $statusBadge,
                $relative,
                $lastFormatted,
            ];
        }

        $table = new Table($output);
        $table->setHeaders(["Name", "Domain", "Token", "Status", "Last Event", "Timestamp (UTC)"]);
        $table->setRows($rows);
        $table->render();

        if ($refresh) {
            $io->success("Cache refreshed.");
        }

        return Command::SUCCESS;
    }
}
