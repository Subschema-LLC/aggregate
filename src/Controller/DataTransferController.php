<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\DataTransfer\DataTransfer;
use App\Service\DataTransfer\DataTransferException;
use App\Service\DataTransfer\TransferOptions;
use App\Service\Operations\BackgroundCommand;
use App\Service\Operations\ProcessingTasks;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class DataTransferController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_EXPORT = 'data_transfer_export';
    public const CSRF_IMPORT = 'data_transfer_import';
    public const CSRF_COPY = 'data_transfer_copy';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly DataTransfer $transfer,
        private readonly ProcessingTasks $tasks,
        private readonly BackgroundCommand $background,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/data-transfer', name: 'app_data_transfer', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        return $this->renderDashboardPage('data_transfer/index.html.twig', [
            'counts' => $this->counts(),
            'packages' => $this->packages(),
            'last_run' => $this->lastRun(),
            'target_configured' => $this->transfer->targetConfigured(),
            'background_problem' => $this->background->problem('app:data:export'),
            'suggested_package' => 'export-'.gmdate('Ymd'),
        ]);
    }

    #[Route('/dashboard/data-transfer/export', name: 'app_data_transfer_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        return $this->start($request, self::CSRF_EXPORT, 'app:data:export', function (array $submitted): array {
            $package = TransferOptions::packageName($this->text($submitted, 'package'));
            $tokens = TransferOptions::tokens($this->lines($submitted, 'website_tokens'));
            $arguments = ['--package='.$package, ...$this->datasetArguments($submitted)];
            foreach ($tokens as $token) {
                $arguments[] = '--website-token='.$token;
            }
            if ($this->checked($submitted, 'consistent')) {
                $arguments[] = '--consistent';
            }
            if ($this->checked($submitted, 'dry_run')) {
                $arguments[] = '--dry-run';
            }

            return $arguments;
        });
    }

    #[Route('/dashboard/data-transfer/import', name: 'app_data_transfer_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        return $this->start($request, self::CSRF_IMPORT, 'app:data:import', function (array $submitted): array {
            $package = TransferOptions::packageName($this->text($submitted, 'package'));
            $onConflict = TransferOptions::onConflict($this->text($submitted, 'on_conflict'));
            $tokens = TransferOptions::tokens($this->lines($submitted, 'website_tokens'));
            $map = TransferOptions::tokenMap($this->lines($submitted, 'map_tokens'));
            $arguments = ['--package='.$package, '--on-conflict='.$onConflict, ...$this->datasetArguments($submitted)];
            foreach ($tokens as $token) {
                $arguments[] = '--website-token='.$token;
            }
            foreach ($map as $old => $new) {
                $arguments[] = '--map-token='.$old.'='.$new;
            }
            if ($this->checked($submitted, 'new_ids')) {
                $arguments[] = '--new-ids';
            }
            if ($this->checked($submitted, 'dry_run')) {
                $arguments[] = '--dry-run';
            }

            return $arguments;
        });
    }

    #[Route('/dashboard/data-transfer/copy', name: 'app_data_transfer_copy', methods: ['POST'])]
    public function copy(Request $request): Response
    {
        return $this->start($request, self::CSRF_COPY, 'app:data:copy', function (array $submitted): array {
            if (!$this->transfer->targetConfigured()) {
                throw new DataTransferException('Set '.DataTransfer::TARGET_URL_ENV.' in the server environment before copying. Do not paste the database URL here.');
            }
            $onConflict = TransferOptions::onConflict($this->text($submitted, 'on_conflict'));
            $tokens = TransferOptions::tokens($this->lines($submitted, 'website_tokens'));
            $map = TransferOptions::tokenMap($this->lines($submitted, 'map_tokens'));
            $arguments = ['--on-conflict='.$onConflict, ...$this->datasetArguments($submitted)];
            foreach ($tokens as $token) {
                $arguments[] = '--website-token='.$token;
            }
            foreach ($map as $old => $new) {
                $arguments[] = '--map-token='.$old.'='.$new;
            }
            if ($this->checked($submitted, 'new_ids')) {
                $arguments[] = '--new-ids';
            }
            if ($this->checked($submitted, 'consistent')) {
                $arguments[] = '--consistent';
            }
            if ($this->checked($submitted, 'dry_run')) {
                $arguments[] = '--dry-run';
            }

            return $arguments;
        });
    }

    /** @param callable(array<string, mixed>): list<string> $arguments */
    private function start(Request $request, string $csrf, string $command, callable $arguments): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $submitted = $request->request->all();
        $token = $submitted['_csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid($csrf, $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_data_transfer');
        }
        try {
            $line = $arguments($submitted);
        } catch (DataTransferException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_data_transfer');
        }
        $username = $this->getUser()?->getUserIdentifier();
        if (is_string($username) && $username !== '') {
            $line[] = '--requested-by='.$username;
        }
        try {
            $this->background->start($command, $line, 'var/data-transfer/last-output.log');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_data_transfer');
        }
        $this->addFlash('success', 'The transfer has started. This page shows the result when it is recorded.');

        return $this->redirectToRoute('app_data_transfer');
    }

    /** @return array<string, int|null> */
    private function counts(): array
    {
        try {
            return $this->transfer->counts();
        } catch (\Throwable $e) {
            $this->logger->error('Data transfer page could not count collected rows.', ['exception' => $e]);

            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function packages(): array
    {
        try {
            return $this->transfer->packages();
        } catch (\Throwable $e) {
            $this->logger->error('Data transfer page could not list packages.', ['exception' => $e]);

            return [];
        }
    }

    /** @return array<string, mixed>|null */
    private function lastRun(): ?array
    {
        try {
            return $this->tasks->latest(DataTransfer::TASK_TYPE)[DataTransfer::TASK_SUBJECT]['attempt'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error('Data transfer page could not read the last run.', ['exception' => $e]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return list<string>
     */
    private function datasetArguments(array $submitted): array
    {
        $datasets = $this->datasets($submitted);
        $users = $this->checked($submitted, 'include_users');
        if ($datasets === [] && !$users) {
            throw new DataTransferException('Choose events, archives, or users.');
        }
        $arguments = [];
        foreach ($datasets as $dataset) {
            $arguments[] = '--dataset='.$dataset;
        }
        if ($users) {
            $arguments[] = '--include-users';
            $arguments[] = '--dataset=users';
        }

        return $arguments;
    }

    /** @param array<string, mixed> $submitted
     *  @return list<string>
     */
    private function datasets(array $submitted): array
    {
        $selected = $submitted['dataset'] ?? [];
        if (!is_array($selected)) {
            throw new DataTransferException('Choose events, archives, or users.');
        }
        $datasets = [];
        foreach ($selected as $dataset) {
            if (!is_string($dataset) || !in_array($dataset, ['events', 'archives'], true)) {
                throw new DataTransferException('Choose events, archives, or users.');
            }
            $datasets[] = $dataset;
        }

        return $datasets;
    }

    /** @param array<string, mixed> $submitted
     *  @return list<string>
     */
    private function lines(array $submitted, string $key): array
    {
        $value = $submitted[$key] ?? '';
        if (!is_string($value)) {
            throw new DataTransferException('A transfer option was not a list of values.');
        }
        $lines = preg_split('/[\s,]+/', $value) ?: [];

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /** @param array<string, mixed> $submitted */
    private function text(array $submitted, string $key): string
    {
        $value = $submitted[$key] ?? '';
        if (!is_string($value)) {
            throw new DataTransferException('A transfer option was not text.');
        }

        return $value;
    }

    /** @param array<string, mixed> $submitted */
    private function checked(array $submitted, string $key): bool
    {
        return ($submitted[$key] ?? null) === '1';
    }
}
