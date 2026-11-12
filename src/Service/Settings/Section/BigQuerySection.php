<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQuerySecrets;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\ServiceAccountKey;
use App\Service\Settings\AbstractViewSyncSection;
use App\Service\Settings\SettingsWithSecrets;
use App\Service\ViewSync\ViewSyncSettings;

/**
 * BigQuery sync settings, as the BigQuery page saves them, with its two
 * secrets: the service account key and the OAuth client secret. Signing in
 * with Google happens in a browser, so it stays on the page; a service
 * account key does the same job from the command line.
 */
final class BigQuerySection extends AbstractViewSyncSection implements SettingsWithSecrets
{
    public function __construct(
        AggregateConfigLoader $config,
        private readonly BigQuerySettings $settings,
        private readonly BigQuerySecrets $secrets,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'bigquery';
    }

    public function page(): string
    {
        return 'Reporting > Sync > BigQuery';
    }

    protected function settings(): ViewSyncSettings
    {
        return $this->settings;
    }

    public function secrets(): array
    {
        return [
            'service_account_key' => 'The service account key, the JSON file Google Cloud gives you',
            'oauth_client_secret' => 'The OAuth client secret, for signing in with Google on the page',
            'google_sign_in' => 'The Google account signed in on the page; it can only be removed here (--remove)',
        ];
    }

    public function secretsSet(?string $target = null): array
    {
        $this->assertTarget($target);
        try {
            $key = $this->secrets->serviceAccountKey() !== null;
        } catch (\InvalidArgumentException) {
            $key = true;
        }

        return [
            'service_account_key' => $key,
            'oauth_client_secret' => $this->secrets->clientSecret() !== '',
            'google_sign_in' => $this->secrets->signInConnection() !== null,
        ];
    }

    public function setSecret(string $name, #[\SensitiveParameter] ?string $value, ?string $target = null, bool $check = false): string
    {
        $this->assertTarget($target);
        if ($name === 'service_account_key') {
            if ($value === null) {
                if (!$check) {
                    $this->secrets->removeServiceAccountKey();
                }

                return self::done($check, 'The service account key was removed.');
            }
            $key = ServiceAccountKey::fromJson(trim($value));
            if (!$check) {
                $this->secrets->storeServiceAccountKey($value);
            }

            return self::done($check, 'The service account key for '.$key->clientEmail.' was saved.');
        }
        if ($name === 'oauth_client_secret') {
            if ($this->secrets->clientSecretFromEnvironment()) {
                throw new \InvalidArgumentException('The OAuth client secret is set by an environment variable, which takes precedence.');
            }
            if ($value === null) {
                throw new \InvalidArgumentException('The OAuth client secret is replaced, not removed: sign out of Google on the BigQuery page to stop using it.');
            }
            BigQuerySecrets::assertClientSecret(trim($value));
            if (!$check) {
                $this->secrets->storeClientSecret($value);
            }

            return self::done($check, 'The OAuth client secret was saved.');
        }

        if ($name === 'google_sign_in') {
            if ($value !== null) {
                throw new \InvalidArgumentException('Signing in with Google happens in a browser, on the BigQuery page. From the command line, use a service account key (bigquery.service_account_key).');
            }
            if (!$check) {
                $this->secrets->clearSignIn();
            }

            return self::done($check, 'The Google sign-in was removed. BigQuery sync uses the service account key, if there is one.');
        }

        throw new \InvalidArgumentException('BigQuery\'s secrets are service_account_key, oauth_client_secret and google_sign_in.');
    }
}
