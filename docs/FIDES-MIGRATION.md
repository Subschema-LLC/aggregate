# Code structure: FIDES

Aggregate's PHP code follows [FIDES](https://github.com/Subschema-LLC/fides)
(draft 0.2): a way of structuring server applications so each concept lives in
one place, every piece of data has one owner, dependencies point inward and
nothing is built by inheritance. FIDES is maintained by the project's authors.
It is unrelated to Ethyca's Fides privacy platform.

**FIDES is required for all development.** New code goes into the five zones
and follows every rule. Code that predates the zones is held by a ratchet in
the build and moves into the zones as it is touched; nothing new is added to
it. The [design decision](DESIGN-DECISIONS.md#fides-required-and-checked-in-the-build)
records why.

Moving code into the zones changes how it is arranged, never what it does:
privacy invariants, YAML settings and their environment overrides, the
dashboard, database views, release packages and updates stay as they are.

## What every change must do

1. **New PHP files go in a zone.** A new file anywhere else in `src/` fails
   the check (`outside-zone`). `src/Kernel.php` is the one exception.
2. **A change to an existing area ports that area.** The classes the change
   touches move into the zones in the same pull request, or in a port pull
   request just before it. When a full port is too large, put the new code in
   the zones anyway, wrap the older code it needs in an integration, and link
   an issue for the rest of the port.
3. **Older code never gets worse.** No new ratchet finding, and no listed file
   grows. When a change removes findings, record them with
   `php tools/arch-check.php --update-baseline`.
4. **The build passes as it is.** `php tools/arch-check.php` and PHPStan pass
   without new entries in `tools/arch-check-baseline.json` or
   `phpstan-baseline.neon`, and without new ignore rules.
5. **The spread test.** A change touches its feature folder, the entities that
   feature names, at most one integration, and the wiring. If it needs more,
   stop and ask whether a concept is spread too far.
6. **A port changes structure, not behavior.** Keep a port and a behavior
   change in separate commits, show that nothing else changed (see
   [proving a port](#proving-a-port)), and name every intended difference in
   the pull request. A difference nobody named is a regression.
7. **Comments say what is true now.** Notes about the move ("replaces the old
   service", "phase 2", "kept for parity") go in the commit message, not the
   code.

### What enforces what

| Rule | Enforced by |
| --- | --- |
| Zones, import direction, purity, no inheritance, `final`, size, depth | `tools/arch-check.php` in CI |
| No new code outside the zones; older code never gets worse | The ratchet in `tools/arch-check.php` |
| Types: undefined fields, dead branches, values a view reads | PHPStan in CI |
| Ownership, one write, messages, porting touched areas, the spread test | Review, with the pull request checklist |
| PascalCase folders and files; views name no entity, a feature view renders only its own templates, no inline CSS or JS | `tools/arch-check.php` in CI (`ZoneFiles`) |
| Views only display values their feature shaped; pages compose features | Review |

## The rules

FIDES's eighteen rules, as they apply here. The
[FIDES rules page](https://github.com/Subschema-LLC/fides/blob/main/docs/rules.md)
gives the reason for each.

1. **One owner.** Only the owner of data changes it; everything else calls its
   named actions. Reading through read-only accessors is fine.
2. **Ownership follows consistency.** An entity owns what it must keep
   consistent and saves it in one write.
3. **Entities stand alone** and refer to each other by id, never by holding
   them.
4. **One use case per feature folder,** named as an action, with its views,
   CSS and JS beside it, including any form whose submission it handles.
5. **Features never call each other.** One records a message, another reacts.
6. **The core is pure.** Entities, features and shared contracts never touch
   the database, files, network, environment or clock directly. Features get
   the time from a clock contract and pass it in.
7. **Dependencies point inward.** Only the wiring knows integrations exist.
8. **No inheritance.** Compose small units; contracts are interfaces.
9. **One write by default.** A `UnitOfWork` makes a multi-entity change
   visible, and saves check a version.
10. **Messages are recorded, saved with the change, then dispatched.** Nothing
    is sent from inside a change, and every handler is safe to run twice.
11. **Settings are declared by their owner and overridden from outside.**
12. **The database holds data, never configuration.**
13. **Views only display.** A view gets plain values shaped by its feature's
    code, never names an entity and holds no rules. Output is escaped.
14. **Pages compose features.** A page template places feature views; a
    feature's view never renders another feature's view. CSS and JS belong to
    the layout, the feature or the page, in separate files, never inline.
15. **One concept, one place:** the spread test above.
16. **Small and shallow.** Files under about 200 lines, lines under about 100
    characters, never deeper than `src/<Zone>/<Owner>/<File>`.
17. **Rules are required; tools are examples.** One casing throughout:
    `PascalCase`, as PSR-4 maps it.
18. **Enforced mechanically:** the architecture check and the static analyser
    run in the build.

## Where code goes

| You are adding | It goes in |
| --- | --- |
| A rule always true for a concept, its values, messages, settings, store contract and read rows | `src/Entities/<Owner>/` |
| Something a user or the system does | `src/Features/<UseCase>/`, named as an action, with its Twig view, CSS and JS |
| A dashboard page | `src/Delivery/<Page>/`: the controller, the page template and page-only CSS and JS |
| A command, message handler or API endpoint | `src/Delivery/<Owner>/` |
| A database store, YAML reader, HTTP client, mailer, or an adapter over older code | `src/Integrations/<Owner>/`, behind a contract |
| A contract no single entity owns, such as the clock | `src/Shared/` |
| A binding from a contract to its integration | `config/wiring.yaml` |
| A Doctrine record | `src/Integrations/Database/` |
| An operator setting | Declared by its owner; the operator YAML stays where it is |

Use `PascalCase` for every folder and file in the zones, matching the class:
`src/Features/SaveAlertRule/SaveAlertRule.php`, `SaveAlertRule.html.twig`,
`SaveAlertRule.css`, `SaveAlertRule.js`. Older templates and assets keep their
snake_case paths under `templates/` and `assets/` until their area moves.

## The zones in Symfony

| Zone | Folder and namespace | Holds | May use |
| --- | --- | --- | --- |
| Entities | `src/Entities/<Owner>`, `App\Entities\<Owner>` | Rules true everywhere, values that check themselves, messages, settings, store contracts, typed read rows | Its own folder only |
| Features | `src/Features/<Owner>`, `App\Features\<Owner>` | One use case each, with its Twig view, CSS and JS | Entities, Shared, its own folder |
| Integrations | `src/Integrations/<Owner>`, `App\Integrations\<Owner>` | Database stores, email, HTTP clients, YAML readers; adapters over older services | Entities, Shared, its own folder, older code |
| Delivery | `src/Delivery/<Owner>`, `App\Delivery\<Owner>` | Pages, controllers, commands, message handlers | Features, Shared, Delivery, older code |
| Shared | `src/Shared`, `App\Shared` | Contracts no entity owns, such as a clock | Shared only |

Older code may call into the zones; the core zones never call older code. An
integration wraps an older service when a feature needs it before it is
ported.

- **Wiring.** Symfony's container stays the wiring and keeps autowiring. Every
  contract-to-integration binding goes in one file, `config/wiring.yaml`, so
  that part stays explicit.
- **No base classes at the edge either.** A command is an invokable class with
  `#[AsCommand]`, and a controller does not extend `AbstractController`.
  Delivery classes take small injected helpers instead of `addFlash()` and
  `redirectToRoute()`.
- **Pages.** Each dashboard page has its own folder, `src/Delivery/<Page>/`.
  FIDES allows one extra level for pages (`delivery/web/<page>/`); Aggregate
  does not need it, because a page is the owner in `src/Delivery/<Page>/`. The
  page template extends the layout and places feature views. When one
  feature's area holds another feature's form, the page renders that form and
  hands it in as a ready-made slot. A one-button action that posts only an id
  can sit in any view.
- **Views.** A Twig namespace `@Features` points at `src/Features`, so a
  feature's view sits with its feature. The feature's PHP shapes the values
  its view prints into a small typed object or array shape PHPStan can read;
  a template never receives or names an entity. Twig escapes by default;
  `|raw` is for slots and other HTML the app built and escaped itself.
- **CSS and JS.** Three owners, loaded in this order: global frame assets with
  the layout, feature assets in the feature folder (they load whenever one of
  the feature's views renders), and page assets in the page folder. No inline
  `<style>`, `style=""` or `<script>`, which also keeps a strict
  Content-Security-Policy possible.
- **Outbox.** Messages go through a Messenger Doctrine transport on the default
  connection, dispatched inside the store's transaction. A rollback test must
  show they commit with the change before anything relies on it.
- **Settings.** The operator YAML stays where it is: moving `aggregate.yaml` or
  `websites.yaml` would break every installation. Settings are read when used,
  so dashboard and `app:config` changes apply without a cache clear (see
  [settings from the command line](CONFIGURATION.md#settings-from-the-command-line));
  each request sees one fixed set of values.
- **Exceptions.** PHP cannot throw an object that does not extend a built-in
  exception, so extending one is the one allowed `extends`. A shared catch
  uses an interface, not a parent class.
- **Doctrine records.** They live in `src/Integrations/Database` and may stay
  open (not `final`), since Doctrine's proxies extend them.

### Groundwork the first zone code adds

None of these exists until a change needs it. The first change that needs one
adds it, in the same pull request, and ticks it here:

- [x] `config/wiring.yaml`, imported by `config/services.yaml`.
- [ ] The `@Features` Twig namespace, and a namespace for page templates in
  `src/Delivery`.
- [ ] AssetMapper paths for `src/Features` and `src/Delivery`, so colocated
  CSS and JS are served, and a renderer that loads them in layout, feature,
  page order.
- [ ] Service and route loading for controllers in `src/Delivery`, including
  the dashboard registrations described in
  [where to make common changes](ARCHITECTURE.md#where-to-make-common-changes).

## The check

`tools/arch-check.php` needs nothing but PHP: it reads source files without
loading them. CI runs it with the PHP checks, and `tests/Tools/ArchCheckTest.php`
covers it.

```bash
php tools/arch-check.php
```

**Zone code must follow every rule:** the import table above, no clock reads,
I/O functions, superglobals or I/O packages in the core, no `extends` except a
built-in exception, no abstract classes, every class `final` (except Doctrine
records in Integrations), at most 200 lines per file, 100 characters per line
and zone/owner/file deep. **Every file in the zones**, Twig, CSS and JS
included, has `PascalCase` folders and names; a Twig view there names no
`App\Entities` class and holds no `<style>`, `<script>`, `style=""` or
inline event handler; and a feature's view includes, embeds, extends or
imports only templates in its own `@Features/<Feature>/` folder.

**Older code is held by a ratchet** in `tools/arch-check-baseline.json`, which
names every finding it holds:

| Finding | Rule | When the ratchet started |
| --- | --- | --- |
| `outside-zone`: a PHP file in `src/` outside the zones | 15, 18 | 452 |
| `app-inheritance`: a class extends another `App\` class | 8 | 41 |
| `abstract-class`: an abstract class exists to be extended | 8 | 6 |
| `not-final`: a concrete class, other than a Doctrine entity, is open | 8 | 113 |
| `file-size`: a file over 200 lines, with its length as a ceiling | 16 | 112 |

A new finding, or a listed file growing, fails the check. Because findings are
listed by name, fixing one cannot pay for another, and renaming a file outside
the zones counts as a new file. A fixed finding also fails until it is
recorded, so it cannot come back:

```bash
php tools/arch-check.php --update-baseline
```

The update only removes findings and lowers file sizes; it never admits a new
finding. Framework classes that extend Symfony types (`Command`,
`AbstractController`, voters, Twig extensions) are not inheritance findings in
older code, because the ports to invokable commands and plain controllers are
tracked as their own sweeps. `BotUserAgentPatterns` is a data table rather than
a concept and is exempt from the size limit.

## Proving a port

The tests passing is not enough: an area worth porting usually has behavior no
test covers. Before and after a port, on the same YAML and the same database
snapshot:

- **Pages.** Fetch every page the area renders, collapse runs of whitespace,
  and diff the HTML.
- **Writes.** For each form or command that writes, restore the snapshot, send
  the same input to both versions, and diff the YAML files and database dump.
- **Rare states.** Put the data into the states the snapshot never reaches (a
  failed sync, an alert that has fired, a disabled feature flag) and compare
  again. Turn anything found into a test.
- **SQL.** Anything that changes generated SQL also runs the fresh-install
  test on every engine.

FIDES's [moving an existing app](https://github.com/Subschema-LLC/fides/blob/main/docs/migrating.md)
describes these comparisons in full, including moving CSS out of a global
stylesheet.

## Phases

### Phase 0: guardrails

No behavior change.

- [x] `tools/arch-check.php`, its baseline, its tests and a CI step.
- [x] A FIDES section in [AGENTS.md](../AGENTS.md).
- [x] This page.
- [x] Email providers composed instead of extending `AbstractEmailProvider`;
  their shared checks are in `EmailSettingValue`.
- [x] The `outside-zone` ratchet: new PHP files outside the zones fail.
- [x] FIDES 0.2 rules in this page, AGENTS.md, CONTRIBUTING.md and the pull
  request template.
- [x] A Twig check for zone views: no entity names, no rendering another
  feature's view or a page template, no inline CSS or JS (rules 13 and 14).
- [x] A casing check for zone folders and colocated files (rule 17).
- [ ] A stricter PHPStan level for the zones than for older code (rule 18).

### Phase 1: Alerts as the pilot

Alerts is the newest code, meets every FIDES concern in one concept and has
thorough service tests. Its rules, sites and destinations travel as plain
arrays, checked by a separate 693-line validator, and `AlertEvaluator` sends
webhooks and email partway through evaluation before saving state. The target:

- `Entities/AlertRule` and `Entities/Destination`, values that check
  themselves and absorb the validator, plus `AlertFired` and `AlertRecovered`
  messages.
- Features: `EvaluateAlerts` (pure), `DeliverAlert` (reacts to the messages),
  `SaveAlertRule`, `SendTestAlert` and `ListAlerts`, each with its own view.
- Integrations: the YAML stores, the event-count query, the webhook sender and
  the email adapter.
- Delivery: the alerts page in `Delivery/Alerts`, placing the feature views.

Ship the port as a release of its own, never mixed with feature requests.

Progress:

- [x] `Entities/AlertRule` and `Entities/Destination` check themselves.
  `AlertRuleValidator` reads the alerts mapping into them and checks what a
  rule refers to (websites, groups, retention) through the entity's
  `RuleReferences` contract; `Integrations/Alerts` supplies the event-name
  check collection uses.
- [x] The YAML store as integrations in `Integrations/Alerts`:
  `AlertsMapping` reads the alerts mapping, `AlertsYaml` writes it,
  `AlertsYamlStore` loads and saves it, `RuleReferenceChecks` checks a rule's
  websites, groups and retention, and `CoveredWebsites` resolves what a rule
  covers. `AlertRuleValidator` and `AlertSettings` only delegate to them; they
  go when their callers move to the features below. `describeCondition()`
  waits for `ListAlerts` and `normalizeInstallationUrl()` for `DeliverAlert`.
- [x] `AlertFired` and `AlertRecovered` in `Entities/Incident`, with the
  stored incident state, and `EvaluateAlerts` as a pure feature. It decides
  one rule and website at a time through the `WindowBreaches` contract
  (`Integrations/Alerts/EventCountWindowBreaches` counts the window) and
  returns an `Evaluation`: the alerts to deliver and the states to save, in
  order. `AlertEvaluator` carries each one out before the next website is
  counted, so a failing count still stops only the websites after it.
- [x] Rule 10 for alerts: the incident state is the outbox (see
  [the design decision](DESIGN-DECISIONS.md#the-incident-state-is-the-alerts-outbox)).
- [x] `DeliverAlert` reacts to `AlertFired` and `AlertRecovered` through the
  `AlertChannel` and `DeliveryLog` contracts; `Integrations/Alerts` supplies
  `AlertChannels` (webhook and `EmailAlerts`) and `TaskDeliveryLog`. Alert and
  incident IDs are in `Entities/Incident/AlertIds`, and a rule's plain-language
  condition in `Entities/AlertRule/RuleDescription`.
- [x] One feature per action, shared by the Alerts page and the `app:alerts:*`
  commands: `SaveAlertRule`, `DeleteAlertRule`, `SaveDestination`,
  `DeleteDestination` and `SaveSigningSecret`, over the `AlertRules`,
  `Destinations` and `SigningSecrets` contracts. Rules about valid settings
  live in the entities and also hold for hand-edited YAML; rules about an
  action (create versus edit, never changing a secret the environment sets,
  dropping a webhook's secret when it becomes email) live in the feature.
- [ ] `SendTestAlert` and `ListAlerts`, the feature views, and the page in
  `Delivery/Alerts`.
- [ ] The `app:alerts:*` commands in `Delivery/Alerts` as invokable commands.

### Phase 2: port as you touch

- Every change to an existing area ports that area (rule 2 of
  [what every change must do](#what-every-change-must-do)).
- Sweep the 67 commands to invokable commands.
- Inject a clock (`Psr\Clock\ClockInterface`) where code reads it directly.

### Phase 3: the hard parts last

- **Event ingestion.** The performance-sensitive path, which FIDES itself rates
  a poor fit: apply the boundaries, not the full shape.
- **The 22 settings sections** extending `AbstractSection`, and the view sync
  family shared by BigQuery, the reporting database and the forensic database,
  move to composition.
- **The Doctrine records** in `src/Entity` move to `Integrations/Database`.

### What stays an exception

- `src/Kernel.php` stays where Symfony expects it, thin.
- `BotUserAgentPatterns` (a data table) is exempt from the size rule.
- The Twig extensions that supply navigation and branding to the layout stay in
  delivery.
- The authenticator and the voters, which Symfony requires to subclass, stay
  thin and stay where they are, outside the zones.
