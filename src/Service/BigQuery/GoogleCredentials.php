<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

/** How Aggregate obtains Google access tokens for the BigQuery API. */
interface GoogleCredentials
{
    public const BIGQUERY_SCOPE = 'https://www.googleapis.com/auth/bigquery';

    /** A current access token, requested again shortly before it expires. @throws BigQueryException */
    public function accessToken(): string;

    /** Who Aggregate acts as, such as the service account's email. @throws BigQueryException */
    public function identity(): string;

    /** The Google Cloud project these credentials belong to, or '' when unknown. */
    public function projectId(): string;
}
