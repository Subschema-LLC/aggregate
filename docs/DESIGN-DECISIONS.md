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
enhanced mode, after consent.

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
hide any cell below a minimum event count. The thresholds are stored in the
database.

**Why.** Suppression in a dashboard protects only that dashboard. In the view, it
applies to every BI report, extract and user, and a BI tool cannot turn it off.

**Trade-off.** Small numbers disappear, and widening the date range in a BI tool
does not bring them back. Thresholds count events, not people, so they reduce
exposure but do not guarantee anonymity.

**What would change it.** Stronger techniques could be added, but only on top of
this contract, not instead of it.

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

### YAML and the command line first; the dashboard is optional

**Decision.** Every setting can be managed in YAML or from the command line, and
the whole application works with the dashboard turned off.

**Why.** Operators automate deployments, keep configuration in version control,
and some want no login page on a collection server.

**Trade-off.** Each new setting is built twice (file and form) on top of one shared
validation. BI thresholds are a known exception: they are stored only in the
database.

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
