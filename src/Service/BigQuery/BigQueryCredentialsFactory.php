<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Builds the credentials for the chosen sign-in method, with setup guidance when something is missing. */
class BigQueryCredentialsFactory
{
    public function __construct(
        private readonly BigQuerySecrets $secrets,
        private readonly HttpClientInterface $http,
    ) {
    }

    /** @param array<string, mixed> $settings Validated BigQuerySettings @throws BigQueryException */
    public function create(array $settings): GoogleCredentials
    {
        switch ($settings[BigQuerySettings::KEY_AUTH]) {
            case BigQuerySettings::AUTH_GOOGLE_CLOUD:
                return new GoogleCloudCredentials($this->http);
            case BigQuerySettings::AUTH_GOOGLE_SIGN_IN:
                $clientId = $settings[BigQuerySettings::KEY_OAUTH_CLIENT_ID];
                $secret = $this->secrets->clientSecret();
                if ($clientId === '' || $secret === '') {
                    throw new BigQueryException('Sign in with Google needs an OAuth client ID and secret. Enter them on the BigQuery page.');
                }
                $token = $this->secrets->refreshToken();
                if ($token === null) {
                    throw new BigQueryException('No administrator has signed in with Google yet. Use “Sign in with Google” on the BigQuery page.');
                }
                if ($this->secrets->signInClientId() !== $clientId) {
                    throw new BigQueryException('The Google sign-in belongs to a different OAuth client. Sign in with Google again.');
                }

                return new GoogleSignInCredentials($clientId, $secret, $token, $this->secrets->signInConnection()['account'] ?? '', $this->http);
            default:
                try {
                    $key = $this->secrets->serviceAccountKey();
                } catch (\InvalidArgumentException $e) {
                    throw new BigQueryException($e->getMessage(), previous: $e);
                }
                if ($key === null) {
                    throw new BigQueryException('No service account key is installed. Upload the JSON key on the BigQuery page, or place it at '.$settings[BigQuerySettings::KEY_CREDENTIALS_FILE].'.');
                }

                return new ServiceAccountCredentials($key, $this->http);
        }
    }

    /** The project that runs load jobs and holds the dataset. @throws BigQueryException */
    public static function project(array $settings, GoogleCredentials $credentials): string
    {
        $project = $settings[BigQuerySettings::KEY_PROJECT] !== '' ? $settings[BigQuerySettings::KEY_PROJECT] : $credentials->projectId();
        if ($project === '') {
            throw new BigQueryException('Enter the Google Cloud project ID that holds the BigQuery dataset.');
        }

        return $project;
    }
}
