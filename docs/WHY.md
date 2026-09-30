# Why Aggregate exists

[Architecture tour](ARCHITECTURE.md) · [Design decisions](DESIGN-DECISIONS.md) · [Glossary](GLOSSARY.md) · [Contributing](../CONTRIBUTING.md)

This page is for people deciding whether to contribute, and for contributors
deciding what a change should look like. It explains the problem Aggregate
addresses, what the project believes, and what it deliberately will not do. The
[README](../README.md) describes the product for adopters.

## The problem

Most web analytics starts by identifying the visitor. It might use a cookie, a
device fingerprint, or, in many "cookieless" tools, a hash of the visitor's IP
address and browser that changes every day. Unique visitors, sessions, bounce
rate and funnels are all built on that identity.

For many organizations the identity is the hard part: public-sector, health,
education and legal sites, and anyone who answers to a privacy office or a data
protection officer. It has to be justified, disclosed and often consented to. And
it is frequently more than they need. They want to know which pages are read,
which campaigns bring people in and whether sign-ups are rising. They don't need
to follow a person.

The same organizations usually own a BI stack already. A separate analytics
dashboard is one more tool to learn, one more data silo, and one more vendor to
review.

## The idea

**Count what happened, not who did it.**

- By default, an event is stored with a sanitized page path, a few coarse
  dimensions and the UTC hour it happened in. That's all: no visitor or
  session identifier, no IP address, no User-Agent string, no exact time.
- More detail (identifiers, custom properties, exact dimensions) is collected
  only for visitors who make an affirmative choice. It stops when they withdraw
  that choice.
- Reports live in the BI tool the organization already uses. Aggregate
  publishes stable SQL views that hide small numbers in the database itself,
  so every report, extract and user gets the same protection.

## What the project believes

These principles decide most design questions. The
[design decisions](DESIGN-DECISIONS.md) page shows how they led to specific choices.

1. **Privacy is enforced by the server, not promised by a banner.** Every rule
   is checked where the data arrives, including for requests that bypass the
   tracker. Invalid privacy configuration stops collection instead of widening it.
2. **Say plainly what it does not do.** The README's "What you give up" section
   comes before the feature list. "Anonymous" is the name of a mode, not a legal
   conclusion, and the docs say where the line sits.
3. **Your BI tool is the front end.** Aggregate collects, protects and publishes
   data. It never draws charts. Its reporting views are a contract that BI
   connections can rely on.
4. **Simple enough to explain on one page.** One events table, few columns,
   plain SQL views. A privacy officer should be able to read the data model and
   understand it.
5. **Easy to install and run anywhere.** Ordinary PHP hosting, five database
   engines, a release ZIP with a browser setup page, and no Docker or build tools
   required. Everything also works without the dashboard.
6. **The operator stays in control.** Settings live in files the operator owns,
   the product can carry their brand, and updates are signed and never
   overwrite their configuration or data.

## What it will not do

Some requests come up often. The project turns them down on purpose; they are
not gaps waiting to be filled.

- **Unique visitors, sessions or bounce rate in anonymous mode.** Computing them
  needs exactly the identifier the mode refuses to create. Enhanced mode, with
  consent, is where identity belongs.
- **Fingerprinting or identity hashes**, even rotating daily ones.
- **In-app charts, funnels or report builders.** Admin screens that manage data
  contracts (columns, SQL previews, regenerating views) are fine; showing
  reports is the BI tool's job.
- **Session replay, heatmaps or a marketing attribution suite.** Other tools do
  these well; Plausible and Matomo are good software for many of these needs.
- **Network calls while collecting,** such as hosted geolocation lookups.
  Geography uses a local database file, and the tracker talks only to the
  installation it came from.

## How this shapes a contribution

Before proposing or reviewing a change, ask:

- Does it create or derive anything that could identify a person without
  consent? Would it make an anonymous row more unique?
- Is the rule enforced on the server, and does it fail closed?
- Does it work with the dashboard turned off, through YAML or the command line?
- Does it work on PostgreSQL, MySQL, MariaDB, SQL Server and SQLite?
- Does it keep existing reporting views and their column meanings stable?
- Can the documentation describe it honestly, including its limits?
- Is there a simpler way that adds fewer tables, columns, settings or
  dependencies?

A "no" is not always a veto, but it needs a clear reason in the pull request.
[AGENTS.md](../AGENTS.md) turns these principles into specific rules, and
[CONTRIBUTING.md](../CONTRIBUTING.md#privacy-invariants) lists the privacy
invariants that tests protect.

## Who builds it

Aggregate is maintained by [Subschema](https://subschema.co/) and developed in
the open. The server and documentation are licensed under AGPL-3.0; the browser
tracker is BSD-3-Clause. The project is in beta: the most useful help right now
is a [test report](BETA-TESTING.md), a fix to a confusing documentation step, a
database integration check, or an accessibility improvement. The
[roadmap](../ROADMAP.md) lists planned work, and
[GitHub issues](https://github.com/Subschema-LLC/aggregate/issues) are the place
to discuss a proposal before starting on it.
