# Design decisions

[Why Aggregate exists](WHY.md) · [Architecture tour](ARCHITECTURE.md) · [Glossary](GLOSSARY.md)

The questions contributors and adopters ask most often are "why does it work this
way?" and "why not just add…?". This page records the main decisions, why they
were made, what they cost, and what would make the project reconsider them. When
a pull request changes one of them, update this page in the same pull request.

Each entry has the same parts: the **decision**, **why** it was made, the
**trade-off** it accepts, and **what would change it**.

## Measurement and privacy

### No identifier in anonymous mode, not even a rotating hash

**Decision.** Anonymous events carry no visitor or session ID and no hash derived
from the IP address or browser, even one that changes daily.

**Why.** Any stable or daily identifier links one person's events together, and
that link is what privacy rules care about. A consent-free mode is only easy to
explain if it cannot follow a person at all.

**Trade-off.** Anonymous mode has no unique visitors, sessions, bounce rate or
funnels. One person can contribute several events to the same count.

**What would change it.** Nothing within anonymous mode. Identity belongs in
enhanced mode, when the consent signal grants it.

### Server-generated UTC hour buckets

**Decision.** The server sets the time of an anonymous event and truncates it to
the UTC hour. Client timestamps are ignored.

**Why.** Exact times help sequence and single out one visitor's actions, and a
client-supplied time can be forged. Hours are still fine-grained enough for daily
and weekly reporting.

**Trade-off.** No real-time view and no minute-level detail. Enhanced events keep
their exact time.

**What would change it.** A reporting need that hours cannot meet would be
handled with a coarser bucket, never a finer one.

### Rules enforced on the server, failing closed

**Decision.** Every collection rule (the kill switch, excluded paths, allowed
properties and goals, consent) is checked in the collection endpoint as well as in
the tracker. Invalid privacy configuration stops collection.

**Why.** Anyone can send requests without the tracker, and an old cached script
can send fields that a newer setting forbids. A rule that only the browser
applies is a suggestion.

**Trade-off.** Features take longer to build, because each one needs a server
check and tests that bypass the tracker.

**What would change it.** Nothing; this is a fixed invariant.

### Anonymous events written immediately; enhanced events through Messenger

**Decision.** The collection endpoint writes anonymous rows itself, already
bucketed. Enhanced events go through Symfony Messenger, which delivers them
immediately by default or through a queue and worker.

**Why.** Writing the anonymous row directly means nothing more detailed than that
row is ever stored, not even briefly in a queue table. Enhanced events carry more
data and may need a queue to scale.

**Trade-off.** Anonymous writes happen during the request, so the database must
keep up with incoming traffic.

**What would change it.** A high-volume design that keeps the same guarantee, such
as batching already-bucketed rows.

### Rate limiting without storing addresses

**Decision.** The rate limiter keys each one-minute window on an HMAC of the
window and the IP address, and discards the address.

**Why.** Abuse protection should not create the kind of identifier the product
avoids. Mixing in the window means the stored keys cannot link one address
across minutes.

**Trade-off.** Limits are per installation and per minute; there is no long-term
blocking of a repeat abuser.

**What would change it.** Sustained abuse would be handled at the web server or
firewall rather than by storing addresses here.

### Enhanced mode follows the consent signal

**Decision.** The tracker uses enhanced mode while its consent signal is granted
and anonymous mode otherwise. With Aggregate's banners, the consent type sets
the signal before a choice: denied for `explicit_eu` (the default) and
`explicit_us`, and the configured default-on categories for `implicit_us`. With a
third-party consent manager, the signal it pushes decides. Administrators
control each step in the dashboard and YAML: the consent type, close button and
default-on categories per banner and region, Global Privacy Control, and whether
Google Consent Mode defaults count or only visitors' updates. See
[Which consent signal sets the mode](PRIVACY-COMPLIANCE.md#which-consent-signal-sets-the-mode).

**Why.** The rules differ by market: opt-in consent in the EU and UK, notice and
opt-out in much of the US. Operators already make that decision in their consent
manager; a fixed rule in the tracker would either contradict it or be worked
around.

**Trade-off.** Aggregate cannot check how a grant was obtained, so a misconfigured
region or consent manager collects enhanced data without valid consent. The
defaults stay conservative: banners are `explicit_eu`, Global Privacy Control
withholds default grants, and no signal means anonymous mode. Explicit types
never apply default grants.

**What would change it.** Adopters needing Aggregate itself to refuse enhanced
mode regardless of the consent manager. The strict collection profile already
does that for every visitor; a per-region equivalent would need a reliable way
to know the visitor's region.

### Consent banners can be restyled, not tilted toward "accept"

**Decision.** Both consent banners let operators change their wording, colors,
button order and the reopen button, but every action button shares one set of
colors, a reject button is always present, and colors must pass WCAG contrast.

**Why.** Operators need banners that match their site and language. A banner that
makes accepting easier or more prominent than refusing steers visitors, which
regulators in several regions treat as invalid consent; a low-contrast banner
hides the choice from some visitors altogether.

**Trade-off.** No highlighted "primary" accept button, which many sites expect,
and some brand palettes are rejected until a darker or lighter shade is chosen.

**What would change it.** Clear, settled guidance that a styled preference is
acceptable in the regions adopters serve, and a way to apply it equally to every
choice.

### The banner's close button rejects unless configured otherwise

**Decision.** Both consent banners have a close button (×). By default, and in
`explicit_eu`, it rejects every optional category and saves that choice. In
`explicit_us` and `implicit_us` it hides the banner for the browser tab's session
without saving a choice, so the default consent stays in effect. Administrators
can choose reject, close without a choice, or no close button for the banner
and for each region. See [Consent behavior](CONSENT-MANAGER.md#consent-behavior).

**Why.** Visitors expect to be able to close a banner. Where consent must be
given actively, closing cannot count as agreement, and a close that only hides
the banner would bring it back on every page; treating it as a rejection is
the safe reading. US-style notices are commonly dismissed without a decision.

**Trade-off.** An EU-style close records a rejection the visitor may not have
meant, and they must reopen the banner to accept. A US-style dismissal lasts one
tab session, so a new tab shows the banner again, and it needs a session-storage
flag (the value `1`, no identifier).

**What would change it.** Guidance that treats closing differently in the regions
adopters serve, or operators needing a dismissal that lasts across sessions.

### Tags outside the tag manager run only when the banner names them

**Decision.** Both consent banners can hold back scripts a website loads itself.
The operator lists each as an external tag (an ID, the name visitors see and a
category); the page marks its scripts `type="text/plain"` with
`data-consent-tag` set to that ID, and the banner starts them, in page order,
once that category is allowed. A script whose ID is not listed never starts.
Each change of choices is also pushed to a `dataLayer` the page already has.
See [Categories and other tags](CONSENT-MANAGER.md#categories-and-other-tags).

**Why.** Many sites load pixels and widgets through their theme, a plugin or
Google Tag Manager rather than Aggregate's tag manager, and a banner that only
governs its own tags leaves those running regardless of the choice. Requiring a
listed ID ties every script the banner starts to a name visitors can see under
its category, and lets an operator move a tag to another category without
editing the page. An inert script element is the established pattern for
this, needs no proxying or rewriting of third-party code, and keeps the page's
nonce-based Content Security Policy in force.

**Trade-off.** The website has to mark each script, and anything it forgets
runs unchecked. Scripts that already ran keep running until a reload after
withdrawal, embedded frames are not covered, and a placeholder runs with full
page privileges, so only markup the site controls should carry the attribute.
The `dataLayer` event is pushed only to an existing array, so Google Tag Manager
must load before the banner to see the first state.

**What would change it.** Demand for frames and other embeds, which would use
the same listed IDs, or for gating scripts that other code inserts later.

### Clicks and forms tracked only where the page marks them

**Decision.** The tracker sends click and form events only for elements a site
marks with [data attributes](TRACKING.md#track-clicks-and-forms-with-data-attributes),
using the event name and property values written in them. It never collects
clicks automatically and never reads element text, link addresses or form fields.

**Why.** Automatic collection records whatever a page contains: button labels
with names, links carrying emails or account IDs, search text. Anonymous mode
promises fixed event names and no free text, and markup the site writes keeps
that promise while making every event one an operator chose. Attributes work
without JavaScript, follow the configured namespace for white labels, and pass
the same data-model and consent checks as `emit()`.

**Trade-off.** Someone has to mark each interaction worth measuring, or add a tag
manager tag that selects elements by CSS; there is no "track everything" switch.
Property keys written as attributes must be lowercase, because HTML lowercases
attribute names.

**What would change it.** A detail that is coarse and non-identifying by
construction, such as an outbound link's host name, if adopters need it. It would
still need a data-model definition before it is stored.

### Organization traffic marked on each website, reported on every event

**Decision.** Team members mark a browser through [marking links](PRIVACY-COMPLIANCE.md#organization-traffic)
that open each tracked website with a short-lived signed code; the tracker there
checks the code with the analytics server and writes the marker in that
website's own cookie or local storage. Every standard-profile event reports
`org_internal_traffic`, `true` or `false`, under that fixed key, whatever the
marker's browser name.

**Why.** Browsers keep each website's storage separate, so a marker saved on the
analytics server never reaches the tracked websites; only a script running on a
website can write there. A link that the tracker already on the page follows
needs no extra script on the website and no third-party cookie. A fixed key keeps
BI filters working when the marker is renamed, and an explicit `false` tells an
unmarked browser apart from an event that was never checked (the strict profile
records no flag).

**Trade-off.** Each website is visited once per browser (the "all websites"
button chains the visits), and its home page must load the tracker; other sites
host a downloaded marker page. Every event carries a few more bytes of JSON.
Rows stored by earlier versions keep the old configured-name key and omit
`false`; they are not converted.

**What would change it.** A browser mechanism that lets a site share a
first-party flag with its sibling websites by consent. Recognizing staff by IP
address is not an option, because anonymous collection keeps no IP-derived data.

### Bots labelled by the server, kept unless the operator drops them

**Decision.** The collection endpoint classifies a request as a bot from its
User-Agent, using a pinned copy of the MIT-licensed
[crawler-user-agents](https://github.com/monperrus/crawler-user-agents) list,
common bot words and the operator's own phrases, or from the tracker's report of
an automated browser. That classification overrides the device class a client
submits. By default (`bot_traffic: label`) bot events are recorded with
`device_class = 'bot'`; `drop` ignores them before rate limiting, lookups or
storage. HTTP client libraries are not on the list, and the strict profile
classifies nothing. See [bot traffic](PRIVACY-COMPLIANCE.md#bot-traffic).

**Why.** A client-supplied device class is a claim the server cannot trust, so a
known crawler running the tracker was counted as a desktop visitor. Labelling
keeps every event, lets reports show or exclude bot traffic with one filter, and
makes a wrong classification visible rather than losing data. Server-side events
are commonly sent with curl or a language's HTTP client, and those cannot run the
browser tracker, so treating them as bots would drop legitimate collection.

**Trade-off.** With the default, reports count bots until they filter
`device_class <> 'bot'`; the `bi_anonymous_*` views keep bot rows so existing
connections do not change meaning. Matching the list costs a fraction of a
millisecond per request. A bot that copies a real browser's User-Agent and hides
automation is not caught, and the pinned list needs regenerating
(`scripts/update-bot-patterns.py`) to learn new crawlers.

**What would change it.** Adopters wanting the anonymous views to exclude bots by
default, which would be a new view version rather than a change in meaning; or a
reliable, privacy-preserving bot signal that does not depend on the User-Agent.

## Data and reporting

### One events table for both modes

**Decision.** Anonymous and enhanced events share the `events` table, told apart
by `privacy_mode`. Optional properties live in a bounded JSON column.

**Why.** One small table is easy to explain, index, archive and delete from. A
table per event type or property multiplies migrations, engine differences and
places where a privacy rule could be missed.

**Trade-off.** The raw table mixes modes, so it must never be granted to routine
BI users; the views do the separating.

**What would change it.** A concrete requirement that the bounded JSON model
cannot meet.

### Suppression inside the database views

**Decision.** The `bi_anonymous_*` views release only completed hours or days and
hide any cell below a minimum event count. The thresholds are YAML settings
compiled into the view SQL when the views are regenerated.

**Why.** Suppression in a dashboard protects only that dashboard. In the view, it
applies to every BI report, extract and user, and a BI tool cannot turn it off.

**Trade-off.** Small numbers disappear, and widening the date range in a BI tool
does not bring them back. Thresholds count events, not people, so they reduce
exposure but do not guarantee anonymity.

**What would change it.** Stronger techniques could be added, but only on top of
this contract, not instead of it.

### A daily view by page group, with complementary suppression

**Decision.** `bi_anonymous_daily_v1` counts completed UTC days by YAML-defined
page group, referrer channel and device class. A daily cell is released only
when it reaches the threshold and the part the hourly view withholds is zero or
at least the threshold. Both views are regenerated together with one threshold.

**Why.** Hourly cells split across path, channel, device and viewport are too
small on low-traffic sites: with the default threshold, a site with about a
thousand page views a day can see almost all of them withheld. Lowering the
threshold weakens protection for every site and still releases little. A
coarser grain releases most of the same traffic. Publishing a second, coarser
view next to the hourly one would let a BI user subtract released hourly rows
from a daily row and recover a withheld count, so the daily view withholds any
cell where that remainder would be small.

**Trade-off.** On busy sites, where most hourly cells are released, the rule
withholds more daily cells; those sites have the hourly view. Page groups are
compiled into SQL, so a change needs regeneration, and patterns are limited to
what every supported database matches exactly with `LIKE`. Comparisons with the
goal and geography views are not covered, as they are not for the hourly view.

**What would change it.** Nothing planned; the roll-up below extends it rather
than replacing it.

### Withheld daily cells rolled up, and coverage in bands

**Decision.** `bi_anonymous_daily_rollup_v1` keeps the daily view's rows and
pools the cells it withholds through coarser levels (devices, then channels,
then pages keeping the channel, then the event and day), each row labelled with
`(other)` values and a `detail_level`. Every row meets the threshold and the
same rule against the hourly view. `bi_anonymous_coverage_v1` reports, per
website and day, a band for the share of events each event view releases.

**Why.** Dropping small cells makes daily totals undercount in a way a BI user
cannot see, and pilots compare those totals with other tools. Pooling keeps
nearly every event in some row while publishing no row smaller than the
threshold. Making the most detailed rows identical to the daily view's means the
two views cannot be compared to learn anything new. Coverage tells a report how
complete it is; a band instead of a number avoids revealing the day's total.

**Trade-off.** Pooled rows lose detail in an order that suits page and channel
reports; a device report has to show the `(other)` remainder. The SQL is a chain
of window functions over five levels. The coverage band bounds the withheld
remainder to a range, and the coverage view depends on the event views, so it
is created after them and engines that track view dependencies need them
replaced in place, never dropped.

**What would change it.** A need for a different pooling order, which would be a
separate view rather than a change to this one.

### The BI tool is the front end

**Decision.** Aggregate never draws charts or builds reports. It publishes
versioned SQL views, and the dashboard only administers the installation. AI
assistants query the same views, with the same read-only account, as BI tools.

**Why.** The organizations it serves already own Power BI, Tableau or Looker.
Building a weaker competing dashboard would cost effort and add another tool to
review, while stable views let their existing reports keep working.

The same design makes AI analysis safer to offer: an assistant with the reporting
account inherits suppression and never sees an identifier, and the glossary views
give it the column meanings it needs.

**Trade-off.** You need a BI tool, SQL, or an AI assistant with a database
connector to see results.

**What would change it.** Nothing; admin previews of SQL and columns are welcome,
report screens are not.

### Views are a versioned contract

**Decision.** View names end in a version (`_v1`). Columns keep their names and
meanings; a change in meaning ships as a new version through a migration.

**Why.** BI reports break silently when a column changes meaning underneath them.

**Trade-off.** Old versions have to be kept for a while, and migrations must be
written for every database engine.

**What would change it.** Nothing; this is how the contract stays trustworthy.

### Saving the data model updates the private views

**Decision.** The private `analytics_custom_*_v1` views follow the data model:
saving it on the Data model page creates the views, or appends the new reporting
columns after the deployed ones, and syncs the glossary, as saving page groups
or disclosure thresholds regenerates the BI views. Deployed columns keep their
order whichever kind a new column is. Removing or renaming a deployed column is
left to **Force recreate views** (or `--force`), because it drops the views and
their grants. The two properties Aggregate records itself,
`org_internal_traffic` and `page_sequence`, are built in: their type is fixed,
consent does not apply, the default model gives each a column, and the form
offers only a checkbox and a column name.

**Why.** Getting the organization-traffic flag or page depth into a report took
four steps: knowing to add the property, choosing the right type and consent
setting (the page refused anything else), naming a column, and regenerating the
views on a separate page. A text column added after numeric ones also needed a
forced recreation, because new views listed text columns first. Appending in
deployed order keeps grants and BI reports intact without that rule, and asking
only for what is actually a choice removes the rest.

**Trade-off.** A model save now changes the database: on MySQL and MariaDB,
whose DDL commits as it goes, a failure can leave some views updated (the
message says to retry). Column order in long-lived views reflects when columns
were added, not the model's order. Models saved before this version keep their
own properties, so the two built-in columns appear only after their boxes are
ticked once.

**What would change it.** Views that need a planned layout, or installations
that want model edits reviewed before any DDL, would call for an option to save
without updating the views.

### Views copied to BigQuery whole, by scheduled load jobs

**Decision.** [BigQuery sync](BIGQUERY.md) copies selected reporting views, never
raw tables, to a BigQuery dataset from a scheduled command. Each sync replaces a
view's table with one load job and is recorded as a processing task. Approved views are offered by default; row-level
or unsuppressed private views must be opted into one by one. Aggregate signs in
with a service account key, the Google Cloud host's account or an administrator's
Google sign-in, through Google's REST API rather than the Google Cloud SDK.

**Why.** Many adopters report from Looker Studio or keep their data in BigQuery,
where a database connection to Aggregate is awkward. Copying the views keeps
suppression and column meanings intact. Replacing whole tables is simple and
correct for suppressed views, whose cells change as thresholds are crossed or
data is archived or deleted, and makes deletions in Aggregate reach the copy at
the next sync. Load jobs cost nothing, unlike streaming inserts. A few REST calls
avoid adding the large Google Cloud client libraries to every installation.

**Trade-off.** Each sync re-reads and re-uploads whole views, which grows with
private row-level views. Data in BigQuery is outside Aggregate's retention, held
by BigQuery's time travel and by tables no longer synced. Signing in with Google
needs the dashboard and each installation's own OAuth client.

**What would change it.** Views too large to copy whole would call for
incremental loads of completed time buckets; a need for other warehouses would
reuse the same export and schedule.

### Views copied whole into a separate reporting database

**Decision.** The [reporting database](REPORTING-DATABASE.md) sync copies
selected reporting views, never raw tables, into same-named tables of a second
database that `REPORTING_DATABASE_URL` names. Each sync fills a staging table and
then swaps it in (one `RENAME TABLE` on MySQL and MariaDB; drop and rename in one
transaction elsewhere), so readers always see a complete copy. It reuses the
BigQuery sync's view catalog, value conversion, private-view opt-in, schedule
and processing tasks. The connection is an environment variable, not a YAML
setting, and a URL that names the application database is refused. The admin
page and `app:reporting-database:configure` write it to `.env.local`, but only
after a test connection that creates and drops a table succeeds.

**Why.** Operators asked for BI access that cannot reach the application
database and for BI queries that do not load the collection server. A full
read replica copies raw tables and needs database-specific setup; copying the
views needs only a second database on any supported engine, keeps the BI
contract (names, columns, suppression) intact, and gives SQLite installations a
server database for BI logins. Replacing whole tables makes deletions,
threshold changes and new custom columns reach the copy at the next sync, with
no incremental bookkeeping. The connection string holds a password, so it
belongs with `DATABASE_URL` in the server environment rather than in YAML that
the dashboard edits. Hand-editing `.env.local` was the step operators got wrong
(unencoded passwords, a typo found only at the next sync), so Aggregate writes
the file the same way the setup page writes `DATABASE_URL`: tested first,
encoded, in `.env.local` only, and never shown back.

**Trade-off.** Copies are only as fresh as their interval. Each sync re-reads
whole views from the application database, which grows with private row-level
views. Writing `.env.local` from the web needs PHP to be able to write it; where
it cannot, or where another file or the server environment sets the variable,
the page explains instead of saving. Recreated tables lose per-table grants, so BI access must be granted on
the database or schema. The tables have no indexes. Tables of deselected views
stay until an administrator drops them.

**What would change it.** Views too large to copy whole would call for
incremental copies of completed time buckets, as for BigQuery; a need for
indexes or live data would favor database-native replication of the views'
source tables to a reporting server that hosts the views itself.

### One scheduled task for every background job

**Decision.** One command, `app:scheduler:run`, runs every five minutes from the
server's scheduler and starts each background job that is turned on and due:
daily maintenance, BigQuery sync and the reporting database sync. Each job keeps
its own command, settings page and interval; the scheduler only decides what is
due. Runs never overlap (a file lock in `var/scheduler`), a failing job does not
stop the others, and the command prints nothing when nothing ran. The
**Scheduled tasks** page and `app:scheduler:status` show whether the server's
task runs, counting only runs without a terminal, and give the exact setup for
the server they run on: a Plesk **Run a PHP script** task when the application
sits under `/var/www/vhosts`, otherwise a crontab line with the PHP binary that
runs Aggregate.

**Why.** Each job used to need its own cron line, and every new job would have
added another. Hosting panels make each line a separate form, and Plesk's
**Run a command** tasks fail in a restricted environment without PHP, which
operators met as `cd: … No such file or directory`. One task, set up once with
values copied from the page, covers future jobs too, and a page that turns
green only when the server itself ran the task proves the setup instead of the
person testing it from a terminal.

**Trade-off.** Jobs share one five-minute cadence, so nothing runs more often,
and a long job delays the next job in the same run. Maintenance moved from a
fixed time of day to 24 hours after its last run. Telling scheduled runs from
runs by hand relies on whether a terminal is attached.

**What would change it.** A job that needs minute-level timing or must run in
parallel with the others, or hosting without any scheduler, which would call for
running due jobs from web requests.

### Two shared tables for job runs and the audit trail

**Decision.** Background jobs record their runs in one `processing_tasks` table
and their outcomes in one `audit_trail` table, instead of a status table per
feature. A run is a row with a type, a subject, a status and failure details; an
exclusive job holds a lock key that a unique index keeps to one running row. The
audit trail gets an entry for every finished run and is reserved for
administrator actions. Maintenance purges both after configurable periods,
keeping each job's latest success and failure. BigQuery sync and analytics
maintenance use them, as does the reporting database sync; BigQuery's own status table was dropped without
migrating its rows.

**Why.** Each new job (a sync, an export, a cleanup) would otherwise add a table
with its own columns, lock and cleanup rules, and operators would look in a
different place for each. A shared shape is easier to query, purge and explain,
and the next job reuses the locking and auditing instead of reimplementing them.

**Trade-off.** Job-specific results go into generic columns (`row_count`,
`external_id`, `details`) rather than typed ones, and status pages read the latest
run per subject instead of a single row. Purging needs a scheduled maintenance
run, and the kept latest runs mean a purge never empties the table completely.

**What would change it.** A job whose results need typed, queryable columns
beyond those, or a volume of runs the purge cannot keep small, would get a table
of its own.

## Platform

### PHP and Symfony

**Decision.** The server is a PHP 8.2+ Symfony application using Doctrine.

**Why.** PHP runs on inexpensive shared hosting and every major hosting panel,
where the people this project serves already run websites. Symfony provides
mature security, forms and routing, Doctrine supports all five databases, and
Messenger gives an optional queue without new infrastructure.

**Trade-off.** Ingestion is not as fast as a purpose-built collector. Large sites
scale through the database and an optional worker.

**What would change it.** Measured workloads that a PHP deployment cannot handle
with those options.

### Five database engines

**Decision.** PostgreSQL, MySQL, MariaDB, SQL Server and SQLite are all supported.

**Why.** BI teams connect to the database their organization already runs, which
is often SQL Server alongside Power BI, PostgreSQL or MySQL on Linux hosts, or
SQLite for a quick trial.

**Trade-off.** Views and migrations need engine-specific SQL, and every engine
must be tested. The fresh-install CI job runs on all five.

**What would change it.** An engine that cannot support the reporting contract.

### PostgreSQL connections kept open by default

**Decision.** The setup page writes `persistent=true` into a PostgreSQL
`DATABASE_URL`, so each PHP-FPM worker keeps its database connection between
requests. A ticked checkbox on the setup page leaves it out, and
`persistent=false` turns it off later. MySQL, MariaDB, SQL Server and SQLite
connections are opened per request as before.

**Why.** Every collection request is short, and opening a PostgreSQL connection
(a new server process and a password check) was its largest cost: the
[load test](LOAD-TESTING.md#results) sustained about twice the requests with
persistent connections. Aggregate keeps no session state on a connection (no
advisory locks, session settings or temporary tables), and PDO rolls back a
transaction a request leaves open, so a reused connection starts clean. MySQL's
connections are cheap to open, and keeping them made no measurable difference.

**Trade-off.** Each PHP-FPM worker holds a connection while it lives, so the
workers on every server count against PostgreSQL's `max_connections`, and
database hosts that allow few connections need the setting off or a pooler.
A credentials change needs a PHP-FPM reload to drop the old connections.

**What would change it.** Hosting where connection limits are commonly too low
for the PHP-FPM workers, or a server runtime that keeps the application loaded
between requests, such as FrankenPHP's worker mode.

### Easy installation over tooling

**Decision.** A release ZIP contains everything needed to run: dependencies and
compiled assets. A browser setup page configures it. The dashboard uses Bulma,
Stimulus and Turbo through AssetMapper, with no Node build, and the tracker is
hand-written source.

**Why.** The people who most need privacy-minimized analytics often install
through a hosting panel's file manager, without SSH, Docker or build tools. A
readable tracker is also easier to audit.

**Trade-off.** The dashboard stays deliberately simple, and front-end tooling
choices are limited.

**What would change it.** A clear benefit that outweighs the install cost, as
[CONTRIBUTING.md](../CONTRIBUTING.md#design-principles) describes.

### Small browser scripts without a build server, cached for five minutes

**Decision.** The tracker, tag manager and consent scripts are served small on
every installation: the Terser build when it matches the source, otherwise a
copy the server compacts itself with its PHP JavaScript parser. Browsers may
reuse them for five minutes, then confirm them with an `ETag`.

**Why.** Visitors' page speed should not depend on how Aggregate was deployed;
a server updated from Git has no Node to build with. Re-downloading every
script on every page view cost visitors bytes for nothing, and five minutes
matches what tag managers commonly use while keeping changes quick.

**Trade-off.** Saved tag and banner changes take up to five minutes to reach
returning visitors. The server-compacted copy keeps names, so it is somewhat
larger than Terser's output. Server-side collection rules still apply to every
event immediately.

**What would change it.** Versioned script URLs in installation snippets, which
would allow long caching with instant updates, at the cost of editing every
site's snippet after each change.

### A smaller tracker for the features settings turn off, on by default

**Decision.** With the page speed setting `tracker_omit_unused_features` on,
which is the default, the configured `/aggregate.js?min=1` leaves out the code
for features the served settings make impossible: page depth while it is off,
and what only the standard profile uses while the strict profile is on. Build
switches in `public/aggregate.js` mark that code; the Terser build and the
server's compactor remove it the same way. Every minified build also shortens
the tracker's internal names. The supplied web server configurations compress
scripts and styles but not HTML.

**Why.** Page depth is off by default and is a sixth of the tracker; the strict
profile needs half of it. Pages cannot turn either back on, so the smaller
build behaves the same, which tests check by running one visit through every
build. One source keeps a single place to fix and review. Compression is the
largest saving of all; leaving HTML out keeps dashboard form tokens away from
compression side channels such as BREACH.

**Trade-off.** Three tracker builds to produce and test instead of one. An
operator who turns page depth on waits up to five minutes for browsers holding
the smaller build, as with any setting. Shortened names make the minified
tracker harder to read; the readable `/aggregate.js` keeps every name and
feature. Operators who prefer one build for everyone can turn the setting off.

**What would change it.** Per-website collection settings, which would make the
build depend on the website as well as the deployment.

### YAML and the command line first; the dashboard is optional

**Decision.** Every setting lives in YAML, can be managed from the command line,
and the whole application works with the dashboard turned off. The database
holds collected data, users and operational records, never settings; tables that
publish configuration to BI tools, such as `analytics_glossary`, are generated
from YAML. The BI disclosure thresholds moved from an `analytics_privacy_settings`
table into YAML to close the last exception, and
`app:analytics:bi-views:regenerate` applies them without the dashboard.

**Why.** Operators automate deployments, keep configuration in version control,
and some want no login page on a collection server. Keeping settings out of the
database keeps the schema small and lets an operator read the whole
configuration in one place.

**Trade-off.** Each new setting is built twice (file and form) on top of one shared
validation. A setting compiled into SQL, like a threshold, only takes effect when
its views are regenerated, so a YAML edit needs that explicit step.

**What would change it.** Nothing; new exceptions need a documented reason.

### Custom JavaScript compiled into the tag manager script

**Decision.** A [custom JavaScript tag](TAG-MANAGER.md#custom-javascript) is the
body of a strict `function (tag) { ... }`. The server parses it with a JavaScript
parser when it is saved or loaded, refuses syntax errors, code that escapes the
function and constructs that run text as code, and then compiles every custom tag
into the website's served `lib.js`, as GTM builds a container. Custom HTML is not
supported.

**Why.** One generated script means one request and code that is ready when the
trigger fires; a separate file per tag, as some tag managers load, arrives too
late for a click that leaves the page, and can be served from a different
version than the container that references it. Compiled functions need no
`eval` in the browser or `'unsafe-eval'` in a Content Security Policy. Checking
at save time explains a mistake to the administrator instead of breaking
visitors' pages, and one shared validator covers the dashboard and YAML.

**Trade-off.** A new PHP dependency (Peast) parses the code. Every page downloads
all of a website's custom code, so it is capped at 20,000 bytes per tag and
65,536 bytes per website. The checks find mistakes, not intent: custom code has
full page privileges and can send data anywhere.

**What would change it.** Custom code large enough that downloading it on every
page costs more than a separate request, or a vendor need that only HTML can
meet, which the roadmap's custom HTML proposal would weigh.

## Operation and project

### Signed release packages that keep the operator's files

**Decision.** Releases are ZIPs signed with a key that the installation already
trusts. Updates never replace `.env.local`, the operator's YAML, website and tag
settings, overrides or `var/`, and they keep backups for rollback.

**Why.** An installation that cannot use Git or Composer still needs safe,
verifiable updates. Checking the signature against a key from the package itself
would prove nothing.

**Trade-off.** Maintainers must manage the signing key, and some changes to
shipped defaults need the local override files.

**What would change it.** A trust mechanism that is at least as independent of
the package.

### Code deployed by another tool is reported, not installed

**Decision.** A third update method, "deployed another way", is for sites that a
hosting panel's Git deployment or a CI/CD pipeline deploys. The application never
installs code for it: the Updates page is read only, showing the deployed commit
and how far behind the branch it is, and one command,
`app:updates:deployed`, runs the post-deployment steps from the tool's deployment
action. The deployed commit is recorded by that command or read from the tool's
Git repository, found next to the site without a setting.

**Why.** Many operators already deploy every site from Git with their panel or
pipeline, and two mechanisms writing the same files would overwrite each other.
Leaving installation to their tool keeps one writer, while the steps every
deployment needs (dependencies, migrations, glossary, assets, cache) still run
the same way as an in-app update, under the same maintenance page and journal.

**Trade-off.** The dashboard cannot install or roll back these deployments, and
the version shown depends on the post-deployment command running, or on finding
the tool's repository. Each tool needs its own setup, which the guides cover.

**What would change it.** Evidence that operators want the dashboard to trigger
their tool's deployment, which would need each tool's API.

### A one-time setup code for browser installation

**Decision.** The browser setup page and `/install` accept nothing until the
visitor enters the code from `SETUP-CODE.txt`, a file only someone with access to
the server can read.

**Why.** Bots watch newly issued HTTPS certificates and complete fresh installers
within minutes. Without the code, one could connect the installation to its own
database or create the first administrator.

**Trade-off.** One extra step: copying the code from the file manager.

**What would change it.** An equally strong way to prove ownership of the server
with less effort.

### One documentation source, linked from the app

**Decision.** The Markdown files in the repository are the only documentation.
They are published as the public documentation site, and the GitHub wiki only
points to it. The dashboard keeps help that depends on the installation or
belongs next to a setting, and links to the site for explanations and
procedures, through one configurable address (`documentation_url`).

**Why.** Copies drift. The dashboard's own explainer pages had already fallen
behind the guides, for example by showing a hardcoded tracker namespace. One
source, checked by the site build and by a test of every link the app uses, stays
correct as the code changes, and operators can point the links at their own
documentation or turn them off.

**Trade-off.** The public site describes the latest release, so an older
installation can link to features it does not have yet. With the links turned
off, the dashboard shows less help, and operators rely on the documentation files
shipped in the application folder.

**What would change it.** Versioned documentation, which would let each
installation link to the guides for its own release.

### AGPL for the server, BSD for the tracker

**Decision.** The server, dashboard and documentation are AGPL-3.0-only. The
browser tracker, `public/aggregate.js`, is BSD-3-Clause.

**Why.** The AGPL keeps improvements to the server open, even when it runs as a
hosted service. The tracker is embedded in other people's websites, so a
permissive license lets any site use or adapt it without licensing questions.

**Trade-off.** Contributors must keep the license headers and the split intact.

**What would change it.** A licensing decision by the maintainers; contributions
do not change it.
