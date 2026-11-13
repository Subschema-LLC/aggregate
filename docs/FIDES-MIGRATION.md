# Moving to FIDES

Aggregate's PHP code is moving, one change at a time, to FIDES: a way of
structuring server applications so each concept lives in one place, every
piece of data has one owner, dependencies point inward and nothing is built by
inheritance. FIDES is a draft (0.1) maintained by the project's authors. It is
unrelated to Ethyca's Fides privacy platform.

This page is the map and the progress record. The
[design decision](DESIGN-DECISIONS.md#code-moving-to-fides-checked-in-the-build)
records why. The move changes how the code is arranged, never what it does:
privacy invariants, YAML settings and their environment overrides, the
dashboard, database views, release packages and updates stay as they are.

## The rules in short

1. Only the owner of data changes it; everything else calls its named actions.
2. An entity owns what it must keep consistent and saves it in one write.
3. Entities stand alone and refer to each other by id.
4. One use case per feature folder, with its view and CSS beside it.
5. Features never call each other; one records a message, another reacts.
6. The core (entities, features, shared contracts) never touches the
   database, files, network, environment or clock directly.
7. Dependencies point inward. Only the wiring knows integrations exist.
8. No inheritance. Compose small units; contracts are interfaces.
9. One write by default; a `UnitOfWork` makes a multi-entity change visible.
10. Messages are recorded, saved with the change, then dispatched.
11. Settings are declared by their owner and overridden from outside.
12. The database holds data, never configuration.
13. Views only display.
14. A change touches its feature, the entities it names, at most one
    integration, and the wiring.
15. Small and shallow: files under about 200 lines, lines under about 100
    characters, never deeper than zone/owner/file.
16. Rules are required; tools are examples.
17. The rules are enforced mechanically.

## The zones in Symfony

New code goes into five folders beside the existing code, which keeps working
and moves over time:

| Zone | Folder and namespace | Holds | May use |
| --- | --- | --- | --- |
| Entities | `src/Entities/<Owner>`, `App\Entities\<Owner>` | Rules true everywhere, values that check themselves, messages, settings, store contracts | Its own folder only |
| Features | `src/Features/<Owner>`, `App\Features\<Owner>` | One use case each, with its Twig fragment and CSS | Entities, Shared, its own folder |
| Integrations | `src/Integrations/<Owner>`, `App\Integrations\<Owner>` | Database stores, email, HTTP clients, YAML readers; adapters over older services | Entities, Shared, its own folder, older code |
| Delivery | `src/Delivery/<Owner>`, `App\Delivery\<Owner>` | Controllers, commands, message handlers | Features, Shared, Delivery, older code |
| Shared | `src/Shared`, `App\Shared` | Contracts no entity owns, such as a clock | Shared only |

Older code may call into the zones; the core zones never call older code.
An integration wraps an older service when a feature needs it before it is
ported.

- **Wiring.** Symfony's container stays the wiring and keeps autowiring. Every
  contract-to-integration binding goes in one file, `config/wiring.yaml`, so
  that part stays explicit.
- **No base classes at the edge either.** Since Symfony 7.3 a command can be
  an invokable class with `#[AsCommand]`, and a controller never needed
  `AbstractController`. Delivery classes take small injected helpers instead
  of `addFlash()` and `redirectToRoute()`.
- **Views.** A Twig namespace `@Features` points at `src/Features`, so a
  feature's fragment sits with its feature. Page templates in delivery extend
  the layout and include those fragments. Twig escapes by default; keep logic
  out by review.
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
- **Doctrine records.** They move to `src/Integrations/Database` and may stay
  open (not `final`), since Doctrine's proxies extend them.

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
and zone/owner/file deep.

**Older code is held by a ratchet** in `tools/arch-check-baseline.json`, which
names every current finding:

| Finding | Rule | At the start |
| --- | --- | --- |
| `app-inheritance`: a class extends another `App\` class | 8 | 41 |
| `abstract-class`: an abstract class exists to be extended | 8 | 6 |
| `not-final`: a concrete class, other than a Doctrine entity, is open | 8 | 113 |
| `file-size`: a file over 200 lines, with its length as a ceiling | 15 | 112 |

A new finding, or a listed file growing, fails the check. Because findings are
listed by name, fixing one cannot pay for another. A fixed finding also fails
until it is recorded, so it cannot come back:

```bash
php tools/arch-check.php --update-baseline
```

The update only removes findings and lowers file sizes; it never admits a new
finding. Framework classes that extend Symfony types (`Command`,
`AbstractController`, voters, Twig extensions) are not findings in older code,
because the ports to invokable commands and plain controllers are tracked as
their own sweeps. `BotUserAgentPatterns` is a data table rather than a concept
and is exempt from the size limit.

## Phases

### Phase 0: guardrails

No behavior change.

- [x] `tools/arch-check.php`, its baseline, its tests and a CI step.
- [x] A FIDES section in [AGENTS.md](../AGENTS.md).
- [x] This page.
- [x] Email providers composed instead of extending `AbstractEmailProvider`;
  their shared checks are in `EmailSettingValue`.

### Phase 1: Alerts as the pilot

Alerts is the newest code, meets every FIDES concern in one concept and has
thorough service tests. Its rules, sites and destinations travel as plain
arrays, checked by a separate 693-line validator, and `AlertEvaluator` sends
webhooks and email partway through evaluation before saving state. The target:

- `Entities/AlertRule` and `Entities/Destination`, values that check
  themselves and absorb the validator, plus `AlertFired` and `AlertRecovered`
  messages.
- Features: `EvaluateAlerts` (pure), `DeliverAlert` (reacts to the messages),
  `SaveAlertRule`, `SendTestAlert` and `ListAlerts`.
- Integrations: the YAML stores, the event-count query, the webhook sender and
  the email adapter.

Ship the port as a release of its own, never mixed with feature requests.

### Phase 2: port as you touch

- A change to an existing area also ports that area.
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

- `BotUserAgentPatterns` (a data table) is exempt from the size rule.
- The Twig extensions that supply navigation and branding to the layout stay in
  delivery.
- `Kernel`, the authenticator and the voters, which Symfony requires to
  subclass, stay thin.
