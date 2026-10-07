# Sync to BigQuery

[Connect BI tools](BI-CONNECTION.md) · [Privacy guide](PRIVACY-COMPLIANCE.md#bigquery-sync) · [Configuration](CONFIGURATION.md#bigquery-sync)

Aggregate can copy reporting views to a Google BigQuery dataset on a schedule, so
you can use them in Looker Studio, Connected Sheets, BigQuery ML or alongside
other data you keep in BigQuery. You choose the views and the interval; Aggregate
replaces each view's table with the view's current contents every time it syncs.

Aggregate still draws no charts. BigQuery sync is one more way to deliver the
same [reporting views](BI-CONNECTION.md) your BI tools already read.

- [How the sync works](#how-the-sync-works)
- [Set it up](#set-it-up)
- [Choose how Aggregate signs in](#choose-how-aggregate-signs-in)
- [Choose the views](#choose-the-views)
- [Schedule the sync](#schedule-the-sync)
- [Settings without the dashboard](#settings-without-the-dashboard)
- [Column types](#column-types)
- [Privacy and data protection](#privacy-and-data-protection)
- [Troubleshooting](#troubleshooting)

## How the sync works

- The command `php bin/console app:bigquery:sync` runs from your scheduler,
  every five minutes. Each selected view is copied when the configured interval
  (15 minutes to 24 hours) has passed since its last sync. Web requests never run
  a sync; **Sync now** on the admin page starts the same command in the
  background.
- Each view becomes one BigQuery table with the view's name, for example
  `my-project.aggregate.bi_anonymous_events_v1`. Aggregate writes the rows to a
  temporary file and replaces the table with one load job (`WRITE_TRUNCATE`).
  The replacement is atomic: a query sees the old copy or the new one, never a
  mix. A view with no rows replaces the table with an empty table of the same
  columns.
- The dataset is created in the configured location on the first sync if it does
  not exist.
- Because every sync replaces the whole table, the copy always matches the view:
  suppression, threshold changes, archiving and deletions in Aggregate reach
  BigQuery at the next sync.
- A view that fails is recorded with its error and retried after 15 minutes (or
  the interval, if shorter); the other views continue. Two scheduled runs, on one
  server or several, never sync the same view at once.
- Load jobs are free in BigQuery. Google bills the storage of the tables and the
  queries you run on them. The approved views are small; private row-level views
  grow with your traffic.

Status for each view (last successful sync, rows, next sync and the last message)
appears on **Reporting → BigQuery sync** and in
`php bin/console app:bigquery:check`. It is kept in the private
`analytics_bigquery_sync` table, which holds no event data.

## Set it up

1. **Prepare Google Cloud.** Pick or create a project and
   [enable the BigQuery API](https://console.cloud.google.com/apis/library/bigquery.googleapis.com).
2. **Connect.** Open **Reporting → BigQuery sync**, choose how Aggregate signs in
   (below), enter the project ID if it is not detected, a dataset name (default
   `aggregate`) and a location such as `US`, `EU` or `europe-west2`. Save, then
   use **Test connection**: it signs in, checks the dataset and confirms that the
   account can run BigQuery jobs, without changing anything.
3. **Choose the views.** The approved views are selected by default.
4. **Schedule.** Add the cron line the page shows, turn sync on and save. Use
   **Sync now** to copy everything immediately.

## Choose how Aggregate signs in

| Method | Best for | What Aggregate stores |
| --- | --- | --- |
| [Service account key](#service-account-key) | Any server | The JSON key, in `config/secrets/` |
| [Automatic on Google Cloud](#automatic-on-google-cloud) | Aggregate running on Google Cloud | Nothing |
| [Sign in with Google](#sign-in-with-google) | Organizations that block service account keys | A refresh token, in `config/secrets/` |

Whichever you choose, the account needs two roles: **BigQuery Job User**
(`roles/bigquery.jobUser`) on the project, and **BigQuery Data Editor**
(`roles/bigquery.dataEditor`) on the dataset. To let Aggregate create the
dataset, grant Data Editor on the project instead, or create the dataset
yourself first and grant the role on it.

### Service account key

1. In the Google Cloud console, open **IAM & Admin → Service accounts** and create
   an account, for example `aggregate-sync`. Grant it the two roles above.
2. Open the account's **Keys** tab, choose **Add key → Create new key → JSON** and
   download the file.
3. On the BigQuery page, choose **Service account key**, select the file (or paste
   its contents) and save.

The key is checked before it is saved and stored in
`config/secrets/bigquery-service-account.json`, readable only by the user that
runs Aggregate. Updates keep `config/secrets`, and release packages and Git never
include it. The page shows the key's account, never the key. To rotate the key,
upload a new one, then delete the old key in the Google Cloud console; **Remove
key from this server** deletes the local copy.

Without the dashboard, place the key file at that path yourself, or point
`bigquery_credentials_file` (or `BIGQUERY_CREDENTIALS_FILE`) at it; a relative
path starts at the application folder. When the path is set this way, the admin
page reads the key but does not replace it.

Some organizations forbid key creation with the
`iam.disableServiceAccountKeyCreation` policy; use one of the other methods
there.

### Automatic on Google Cloud

When Aggregate runs on Compute Engine, Cloud Run, GKE (with Workload Identity) or
App Engine, it can use the service account attached to that host through the
local metadata server. No key is stored, and the project is detected
automatically.

Grant the attached service account the two roles above. On Compute Engine, the
VM's access scopes must include BigQuery, or "Allow full access to all Cloud
APIs". Elsewhere the metadata server does not exist, and the connection test
says so.

### Sign in with Google

An administrator signs in with their own Google account, and the sync acts as
that account with its BigQuery permissions. Each installation uses its own OAuth
client:

1. In **APIs & Services → OAuth consent screen**, set up the consent screen. In a
   Google Workspace organization choose the **Internal** user type. Otherwise
   choose External and **publish** the app: while an app is in "Testing", Google
   ends its sign-ins after 7 days and the sync stops.
2. In **APIs & Services → Credentials**, create an **OAuth client ID** of type
   **Web application** and add the **Authorized redirect URI** shown on the
   BigQuery page, for example
   `https://analytics.example.com/dashboard/bigquery/google/callback`. Set
   `app_host` so that this address matches the one people use.
3. Enter the client ID and secret on the BigQuery page, save, and choose **Sign in
   with Google**. Approve BigQuery access.

Aggregate requests offline access with PKCE and keeps only the refresh token and
the account's email, in `config/secrets/bigquery-google-sign-in.json` with the
client secret (unless `BIGQUERY_OAUTH_CLIENT_SECRET` provides it).
**Disconnect** revokes the sign-in at Google and deletes it. Signing in again
replaces and revokes the previous sign-in.

Signing in needs the admin dashboard, because Google returns to it. A deployment
without the dashboard uses a service account key or Google Cloud credentials.

## Choose the views

**Approved views** can be selected freely; they are the routine BI contract:

| View | Contents |
| --- | --- |
| `bi_anonymous_events_v1` | Hourly anonymous event counts, small cells withheld |
| `bi_anonymous_goals_v1` | Daily anonymous goal counts, small cells withheld |
| `bi_anonymous_geo_events_v1` | Daily anonymous geography counts, small areas pooled (needs [optional geography](CONFIGURATION.md#optional-coarse-geography)) |
| The seven `bi_dim_*_v1` views, `bi_glossary_values_v1`, `bi_glossary_columns_v1` | Labels and column descriptions |

**Private views** hold individual retained events or unsuppressed counts. Each one
must be selected separately, and the admin page asks you to confirm that it may
leave the server; in YAML it goes under `bigquery_private_views`, never
`bigquery_views`:

| View | Contents |
| --- | --- |
| `analytics_custom_events_v1`, `analytics_custom_pageviews_v1`, `analytics_custom_goals_v1` | Every retained raw row in both privacy modes, with [modeled properties](DATA-MODEL.md); regenerate them first |
| `analytics_archived_events_v1`, `analytics_archived_pageviews_v1`, `analytics_archived_goals_v1` | Archived counts without suppression |

Raw tables, such as `events`, are never offered.

Removing a view from the selection stops its sync; its BigQuery table stays until
you delete it there. The same applies when you turn sync off.

## Schedule the sync

Add the line from the admin page to the crontab of the user that runs Aggregate,
for example:

```cron
*/5 * * * * cd /var/www/aggregate && php bin/console app:bigquery:sync --no-interaction
```

Each view syncs about once per interval (a run within five minutes of the due
time counts). The page warns when the command has not run recently.

| Command | Purpose |
| --- | --- |
| `app:bigquery:sync` | Sync the views that are due; exits with an error when a view fails |
| `app:bigquery:sync --force` | Sync every selected view now |
| `app:bigquery:sync --view=bi_anonymous_goals_v1` | Limit a run to selected views (repeatable) |
| `app:bigquery:sync --dry-run [--output=DIR]` | Export locally and report rows and columns, without credentials or uploads; `--output` keeps the NDJSON and schema files |
| `app:bigquery:sync --json` | Machine-readable results |
| `app:bigquery:check` | Show the settings and status, then test the connection (`--no-connect` skips the test) |

**Sync now** starts `app:bigquery:sync --force` in the background, which needs
`proc_open` on a Unix-like server; otherwise run the command yourself. Its output
goes to `var/bigquery/last-run.log`. Temporary export files are written to
`var/bigquery` and deleted after each upload.

## Settings without the dashboard

Every setting except the Google sign-in can be managed in the active environment
of `config/aggregate.yaml` (or its environment-specific file). Uppercase
environment variables take precedence and lock the matching admin fields; lists
in the environment are comma-separated.

```yaml
bigquery_enabled: true
bigquery_project_id: my-analytics-123       # Empty: the key's or Google Cloud host's project
bigquery_dataset: aggregate
bigquery_location: US                        # US, EU or a region such as europe-west2
bigquery_auth: service_account               # service_account, google_cloud or google_sign_in
bigquery_credentials_file: config/secrets/bigquery-service-account.json
bigquery_oauth_client_id: ''                 # Sign in with Google only
bigquery_views:                              # Approved views
  - bi_anonymous_events_v1
  - bi_anonymous_goals_v1
  - bi_dim_website_token_v1
bigquery_private_views: []                   # Row-level or unsuppressed views, by explicit opt-in
bigquery_interval_minutes: 60                # 15, 30, 60, 120, 180, 360, 720 or 1440
```

| Setting | Environment variable | Default |
| --- | --- | --- |
| `bigquery_enabled` | `BIGQUERY_ENABLED` | `false` |
| `bigquery_project_id` | `BIGQUERY_PROJECT_ID` | empty |
| `bigquery_dataset` | `BIGQUERY_DATASET` | `aggregate` |
| `bigquery_location` | `BIGQUERY_LOCATION` | `US` |
| `bigquery_auth` | `BIGQUERY_AUTH` | `service_account` |
| `bigquery_credentials_file` | `BIGQUERY_CREDENTIALS_FILE` | `config/secrets/bigquery-service-account.json` |
| `bigquery_oauth_client_id` | `BIGQUERY_OAUTH_CLIENT_ID` | empty |
| — | `BIGQUERY_OAUTH_CLIENT_SECRET` | stored by the admin page |
| `bigquery_views` | `BIGQUERY_VIEWS` | every approved view |
| `bigquery_private_views` | `BIGQUERY_PRIVATE_VIEWS` | none |
| `bigquery_interval_minutes` | `BIGQUERY_INTERVAL_MINUTES` | `60` |

Invalid settings stop the sync with an error instead of guessing.

## Column types

Columns keep their names. Types follow the documented view contracts, so a
dataset has the same schema whichever database Aggregate uses:

| Columns | BigQuery type |
| --- | --- |
| `event_count`, `id`, `*_sort`, `sort_order` | `INT64` |
| `event_hour`, `created_at`, `archived_at` | `TIMESTAMP` (UTC) |
| `event_day` | `DATE` |
| `is_fallback`, `is_default_locale` | `BOOL` |
| Numeric reporting columns in the custom views | `INT64` or `FLOAT64`, from the [data model](DATA-MODEL.md) type |
| Everything else | `STRING` |

A value that does not fit its type stops that view's sync with an error naming
the view and column (never the value), and nothing is uploaded for it. Every
column is nullable.

## Privacy and data protection

- **Data leaves your server.** Syncing copies view rows to Google, which
  processes them on your behalf. Cover the transfer in your records of
  processing, data processing terms and notices, and choose a dataset location
  that fits your requirements. See the [privacy guide](PRIVACY-COMPLIANCE.md#bigquery-sync).
- **Approved views carry their suppression.** Small cells are already withheld
  in the rows Aggregate copies. Anyone who can read the dataset sees the same
  cells a routine BI user sees; keep the same rules about joining views.
- **Private views are row-level.** Select them only for an approved purpose, and
  restrict access to the dataset as you would to the raw `events` table.
- **Retention.** Each sync replaces the table, so data Aggregate deletes leaves
  the copy at the next sync. BigQuery keeps earlier versions of a table for its
  time-travel window (up to 7 days, configurable per dataset) and a further
  fail-safe period. Tables of deselected views, or of all views after sync is
  turned off, stay until you delete them.
- **Credentials.** Keys and sign-in tokens are stored only in `config/secrets`,
  readable by the application's user, and never shown, logged or included in
  exports. Error messages name views and columns; values that BigQuery quotes in
  its own errors are removed before they are stored or shown.

## Troubleshooting

| Message | What to do |
| --- | --- |
| "needs the BigQuery Job User role…" (HTTP 403) | Grant the roles listed above to the account shown by **Test connection**. |
| "Enable the BigQuery API" | Enable the BigQuery API for the project. |
| "The dataset … is in US, not EU" | A dataset keeps its location. Set the location to the dataset's, or choose another dataset name. |
| "No Google Cloud metadata server answered" | Aggregate is not running on Google Cloud; use a service account key. |
| "The Google sign-in has expired or was revoked" | Sign in again. Publish the OAuth app or make it Internal so sign-ins do not end after 7 days. |
| "The view … cannot be read in this database" | Run the database migrations; regenerate the custom views; turn on geography for `bi_anonymous_geo_events_v1`. |
| "The scheduled sync is not running" | Add the cron line, as the user that runs Aggregate. |
| "Sync now" is not available | The server cannot start background processes; run `php bin/console app:bigquery:sync --force`. |
