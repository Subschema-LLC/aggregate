<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\GoalDefinitions;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The conversion goals collection accepts. The same goals are reached with
 * "app:config goals" and in config/goals.local.yaml; all three check them
 * with GoalDefinitions.
 */
final class GoalsController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'goals';
    /** Empty rows offered for new goals. */
    private const NEW_ROWS = 3;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly GoalDefinitions $goals,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/goals', name: 'app_goals', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessPermitted();

        try {
            $rows = self::rows($this->goals->all());
            $error = null;
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->logger->error('Failed to load conversion goals.', ['exception' => $e]);
            $rows = [];
            $error = $e->getMessage().' Correct '.GoalDefinitions::LOCAL_FILE.' (or '.GoalDefinitions::SHIPPED_FILE.' when there is no local file) before saving here.';
        }

        return $this->page($rows, $error);
    }

    #[Route('/dashboard/goals/save', name: 'app_goals_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $token = $request->request->get('_csrf_token');
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_goals');
        }

        $submitted = $request->request->all('goals');
        try {
            $goals = self::fromRows($submitted);
            $saved = $this->goals->save($goals);
        } catch (\InvalidArgumentException $e) {
            return $this->page(self::submittedRows($submitted), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save conversion goals.', ['exception' => $e]);
            $this->addFlash('error', 'The goals could not be saved: '.$e->getMessage());

            return $this->redirectToRoute('app_goals');
        }

        $this->addFlash('success', sprintf(
            'Saved %d %s. Collection uses them from the next request. Publish changed labels to BI tools from the BI glossary page or with app:analytics:glossary:sync.',
            count($saved),
            count($saved) === 1 ? 'goal' : 'goals',
        ));

        return $this->redirectToRoute('app_goals');
    }

    /**
     * The goals from the page's rows: a row without a code is an unused new
     * row, and a row marked for removal is left out.
     *
     * @param array<mixed> $rows
     *
     * @return array<string, array{label: mixed, enabled: bool, anonymous: bool}>
     */
    public static function fromRows(array $rows): array
    {
        $goals = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = is_string($row['code'] ?? null) ? trim($row['code']) : '';
            if ($code === '' || !empty($row['remove'])) {
                continue;
            }
            if (isset($goals[$code])) {
                throw new \InvalidArgumentException(sprintf('The goal code "%s" is listed twice.', $code));
            }
            $goals[$code] = [
                'label' => is_string($row['label'] ?? null) ? trim($row['label']) : '',
                'enabled' => !empty($row['enabled']),
                'anonymous' => !empty($row['anonymous']),
            ];
        }

        return $goals;
    }

    /**
     * @param array<string, array{label: string, enabled: bool, anonymous: bool}> $goals
     *
     * @return list<array{code: string, label: string, enabled: bool, anonymous: bool, existing: bool}>
     */
    private static function rows(array $goals): array
    {
        $rows = [];
        foreach ($goals as $code => $goal) {
            $rows[] = ['code' => $code, 'label' => $goal['label'], 'enabled' => $goal['enabled'], 'anonymous' => $goal['anonymous'], 'existing' => true];
        }

        return $rows;
    }

    /**
     * The rows as submitted, so a refused save keeps what was typed.
     *
     * @param array<mixed> $submitted
     *
     * @return list<array{code: string, label: string, enabled: bool, anonymous: bool, existing: bool}>
     */
    private static function submittedRows(array $submitted): array
    {
        $rows = [];
        foreach ($submitted as $row) {
            if (!is_array($row) || (trim((string) ($row['code'] ?? '')) === '' && trim((string) ($row['label'] ?? '')) === '')) {
                continue;
            }
            $rows[] = [
                'code' => is_string($row['code'] ?? null) ? $row['code'] : '',
                'label' => is_string($row['label'] ?? null) ? $row['label'] : '',
                'enabled' => !empty($row['enabled']),
                'anonymous' => !empty($row['anonymous']),
                'existing' => !empty($row['existing']),
            ];
        }

        return $rows;
    }

    /** @param list<array{code: string, label: string, enabled: bool, anonymous: bool, existing: bool}> $rows */
    private function page(array $rows, ?string $error, int $status = Response::HTTP_OK): Response
    {
        for ($i = 0; $i < self::NEW_ROWS; ++$i) {
            $rows[] = ['code' => '', 'label' => '', 'enabled' => true, 'anonymous' => true, 'existing' => false];
        }

        try {
            $fromDefaults = $this->goals->source() === GoalDefinitions::SHIPPED_FILE;
        } catch (\InvalidArgumentException|\RuntimeException) {
            $fromDefaults = false;
        }

        return $this->render('settings/goals.html.twig', [
            'goal_rows' => $rows,
            'goals_error' => $error,
            'goals_file' => GoalDefinitions::LOCAL_FILE,
            'goals_from_defaults' => $fromDefaults,
            'max_goals' => GoalDefinitions::MAX_GOALS,
        ], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store, max-age=0']));
    }
}
