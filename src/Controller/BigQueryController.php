<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQueryBackgroundSync;
use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\BigQuerySecrets;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\BigQuerySyncRunner;
use App\Service\BigQuery\GoogleSignIn;
use App\Service\BigQuery\ServiceAccountKey;
use App\Service\DropInScripts;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Admin page for BigQuery sync. The settings it saves are the same YAML keys
 * app:bigquery:sync reads; credentials are kept in config/secrets.
 */
final class BigQueryController extends AbstractController
{
    use DashboardAccess;
    use ViewSyncAdmin;

    public const CSRF_TOKEN_ID = 'bigquery_settings';
    private const ROUTE = 'app_bigquery';
    private const SESSION_SIGN_IN = 'bigquery_google_sign_in';
    private const SESSION_SYNC_REQUESTED = 'bigquery_sync_requested_at';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly BigQuerySettings $settings,
        private readonly BigQuerySecrets $secrets,
        private readonly BigQuerySyncRunner $runner,
        private readonly BigQueryBackgroundSync $background,
        private readonly GoogleSignIn $signIn,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/bigquery', name: 'app_bigquery', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        [$settings, $error] = $this->loadSyncSettings();

        $key = null;
        $keyError = null;
        try {
            $key = $error === null ? $this->secrets->serviceAccountKey() : null;
        } catch (\InvalidArgumentException $e) {
            $keyError = $e->getMessage();
        }
        try {
            $connection = $this->secrets->signInConnection();
            $hasClientSecret = $this->secrets->clientSecret() !== '';
        } catch (\RuntimeException $e) {
            $connection = null;
            $hasClientSecret = false;
            $keyError ??= $e->getMessage();
        }
        return $this->render('bigquery/index.html.twig', [
            ...$this->syncPage($request, $settings, $error),
            'auth_methods' => BigQuerySettings::AUTH_METHODS,
            'key' => $key === null ? null : ['email' => $key->clientEmail, 'project' => $key->projectId],
            'key_error' => $keyError,
            'key_path' => $settings[BigQuerySettings::KEY_CREDENTIALS_FILE],
            'can_store_key' => $this->settings->canStoreKeyFile(),
            'sign_in' => $connection,
            'has_client_secret' => $hasClientSecret,
            'client_secret_from_environment' => $this->secrets->clientSecretFromEnvironment(),
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    #[Route('/dashboard/bigquery/status', name: 'app_bigquery_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->syncStatusResponse();
    }

    /** Sign-in method, key, OAuth client and Google Cloud project. */
    #[Route('/dashboard/bigquery/connection', name: 'app_bigquery_connection', methods: ['POST'])]
    public function saveConnection(Request $request): Response
    {
        if (($response = $this->guard($request, ['auth', 'project', 'dataset', 'location', 'oauth_client_id', 'oauth_client_secret', 'service_account_key_json'])) !== null) {
            return $response;
        }
        $submitted = $request->request->all();
        try {
            $upload = $request->files->get('service_account_key_file');
            $json = $upload instanceof UploadedFile ? $this->readUpload($upload) : trim((string) ($submitted['service_account_key_json'] ?? ''));
            $secret = trim((string) ($submitted['oauth_client_secret'] ?? ''));
            // Check everything first, so an invalid key or setting saves nothing.
            $key = $json !== '' ? ServiceAccountKey::fromJson($json) : null;
            if ($secret !== '') {
                BigQuerySecrets::assertClientSecret($secret);
            }
            $this->settings->save(array_filter([
                BigQuerySettings::KEY_AUTH => $submitted['auth'] ?? null,
                BigQuerySettings::KEY_PROJECT => $submitted['project'] ?? null,
                BigQuerySettings::KEY_DATASET => $submitted['dataset'] ?? null,
                BigQuerySettings::KEY_LOCATION => $submitted['location'] ?? null,
                BigQuerySettings::KEY_OAUTH_CLIENT_ID => $submitted['oauth_client_id'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));
            if ($key !== null) {
                $this->secrets->storeServiceAccountKey($json);
                $this->addFlash('success', 'The service account key for '.$key->clientEmail.' was saved.');
            }
            if ($secret !== '') {
                $this->secrets->storeClientSecret($secret);
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute(self::ROUTE);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save BigQuery connection settings.', ['exception' => $e]);
            $this->addFlash('error', $e instanceof \RuntimeException ? $e->getMessage() : 'The settings could not be saved. Check the application log and file permissions.');

            return $this->redirectToRoute(self::ROUTE);
        }
        $this->addFlash('success', 'The BigQuery connection settings were saved. Use “Test connection” to check them.');

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/dashboard/bigquery/views', name: 'app_bigquery_views', methods: ['POST'])]
    public function saveViews(Request $request): Response
    {
        return $this->saveSyncViews($request);
    }

    #[Route('/dashboard/bigquery/schedule', name: 'app_bigquery_schedule', methods: ['POST'])]
    public function saveSchedule(Request $request): Response
    {
        return $this->saveSyncSchedule($request);
    }

    #[Route('/dashboard/bigquery/key/remove', name: 'app_bigquery_key_remove', methods: ['POST'])]
    public function removeKey(Request $request): Response
    {
        if (($response = $this->guard($request)) !== null) {
            return $response;
        }
        try {
            $this->secrets->removeServiceAccountKey();
            $this->addFlash('success', 'The service account key was removed from this server. Delete the key in the Google Cloud console too if it is no longer needed.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException ? $e->getMessage() : 'The key could not be removed.');
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/dashboard/bigquery/test', name: 'app_bigquery_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        if (($response = $this->guard($request)) !== null) {
            return $response;
        }
        try {
            $check = $this->runner->check();
            $this->addFlash('success', sprintf(
                'Connected as %s. Project %s can run BigQuery jobs. Dataset %s %s.',
                $check['identity'],
                $check['project'],
                $check['dataset'],
                $check['dataset_exists'] ? 'exists in '.$check['dataset_location'] : 'does not exist yet; the first sync creates it in '.$check['location'],
            ));
        } catch (BigQueryException|\InvalidArgumentException $e) {
            $this->addFlash('error', 'The connection test failed: '.$e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('BigQuery connection test failed.', ['exception' => $e]);
            $this->addFlash('error', 'The connection test failed; see the application log.');
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/dashboard/bigquery/sync', name: 'app_bigquery_sync', methods: ['POST'])]
    public function syncNow(Request $request): Response
    {
        return $this->startSync($request, function (): void {
            if (!$this->settings->toArray()[BigQuerySettings::KEY_ENABLED]) {
                throw new \RuntimeException('Turn BigQuery sync on before syncing.');
            }
        });
    }

    #[Route('/dashboard/bigquery/google/start', name: 'app_bigquery_google_start', methods: ['POST'])]
    public function googleStart(Request $request): Response
    {
        if (($response = $this->guard($request)) !== null) {
            return $response;
        }
        try {
            $clientId = $this->settings->toArray()[BigQuerySettings::KEY_OAUTH_CLIENT_ID];
            if ($clientId === '' || $this->secrets->clientSecret() === '') {
                throw new \InvalidArgumentException('Save the OAuth client ID and secret first.');
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException ? $e->getMessage() : 'Google sign-in could not start.');

            return $this->redirectToRoute(self::ROUTE);
        }
        $start = $this->signIn->start($clientId, $this->redirectUri());
        $request->getSession()->set(self::SESSION_SIGN_IN, ['state' => $start['state'], 'verifier' => $start['verifier'], 'client_id' => $clientId, 'expires' => time() + 600]);

        return $this->redirect($start['url']);
    }

    #[Route('/dashboard/bigquery/google/callback', name: 'app_bigquery_google_callback', methods: ['GET'])]
    public function googleCallback(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $pending = $request->getSession()->get(self::SESSION_SIGN_IN);
        $request->getSession()->remove(self::SESSION_SIGN_IN);
        $state = $request->query->get('state');
        if (!is_array($pending) || !is_string($state) || !hash_equals((string) ($pending['state'] ?? ''), $state) || (int) ($pending['expires'] ?? 0) < time()) {
            $this->addFlash('error', 'The Google sign-in could not be matched to this session or took too long. Start it again.');

            return $this->redirectToRoute(self::ROUTE);
        }
        $error = $request->query->get('error');
        if (is_string($error)) {
            $this->addFlash('error', $error === 'access_denied' ? 'Google sign-in was cancelled.' : 'Google did not complete the sign-in ('.substr(preg_replace('/[^a-z_]/', '', $error) ?? '', 0, 60).').');

            return $this->redirectToRoute(self::ROUTE);
        }
        $code = $request->query->get('code');
        try {
            if (!is_string($code) || $code === '' || strlen($code) > 2048) {
                throw new BigQueryException('Google returned no authorization code. Start the sign-in again.');
            }
            $result = $this->signIn->exchange($code, (string) $pending['verifier'], (string) $pending['client_id'], $this->secrets->clientSecret(), $this->redirectUri());
            $previous = $this->secrets->refreshToken();
            $this->secrets->storeSignIn($result['refresh_token'], (string) $pending['client_id'], $result['account'], new \DateTimeImmutable());
            if ($previous !== null && $previous !== $result['refresh_token']) {
                $this->signIn->revoke($previous);
            }
            $this->settings->save([BigQuerySettings::KEY_AUTH => BigQuerySettings::AUTH_GOOGLE_SIGN_IN]);
            $this->addFlash('success', 'Signed in with Google'.($result['account'] !== '' ? ' as '.$result['account'] : '').'. BigQuery sync now acts as this account.');
        } catch (BigQueryException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Google sign-in for BigQuery failed.', ['exception' => $e]);
            $this->addFlash('error', 'Google sign-in failed; see the application log.');
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/dashboard/bigquery/google/disconnect', name: 'app_bigquery_google_disconnect', methods: ['POST'])]
    public function googleDisconnect(Request $request): Response
    {
        if (($response = $this->guard($request)) !== null) {
            return $response;
        }
        try {
            $token = $this->secrets->refreshToken();
            $this->secrets->clearSignIn();
            $revoked = $token === null || $this->signIn->revoke($token);
            $this->addFlash('success', $revoked
                ? 'Google sign-in was removed and its access revoked.'
                : 'Google sign-in was removed from this server. Google could not be reached to revoke it; remove Aggregate’s access in your Google account’s security settings.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \RuntimeException ? $e->getMessage() : 'The sign-in could not be removed.');
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    private function syncRunner(): BigQuerySyncRunner
    {
        return $this->runner;
    }

    private function syncBackground(): BigQueryBackgroundSync
    {
        return $this->background;
    }

    private function syncLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /** The redirect URI to register with the OAuth client. */
    private function redirectUri(): string
    {
        $host = DropInScripts::normalizeAppHost($this->config->getWithEnvFallback('app_host', ''));

        return $host !== null
            ? $host.$this->generateUrl('app_bigquery_google_callback')
            : $this->generateUrl('app_bigquery_google_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function readUpload(UploadedFile $upload): string
    {
        if (!$upload->isValid() || $upload->getSize() > 65536) {
            throw new \InvalidArgumentException('The key file could not be uploaded. Choose the JSON key file again (at most 64 KB).');
        }
        $json = @file_get_contents($upload->getPathname(), false, null, 0, 65537);
        @unlink($upload->getPathname());

        return is_string($json) ? $json : throw new \InvalidArgumentException('The uploaded key file could not be read.');
    }
}
