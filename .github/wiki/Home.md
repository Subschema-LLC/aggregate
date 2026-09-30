# Aggregate Analytics

Aggregate is self-hosted, privacy-focused analytics infrastructure. It counts page
views and events without identifying visitors, collects more detail only with
consent, and publishes stable SQL views that Power BI, Tableau or any other BI
tool can read. It installs on ordinary PHP hosting from a release ZIP.

## The documentation is at [subschema-llc.github.io/aggregate](https://subschema-llc.github.io/aggregate/)

The documentation site is searchable and needs no account. It is built from the
Markdown files in the repository, so it describes the current release and is
reviewed together with the code. This wiki only points you to the right page.

## Find what you need

| I want to… | Read |
| --- | --- |
| Understand what Aggregate does, and what it deliberately does not do | [Overview](https://subschema-llc.github.io/aggregate/start/overview) · [Why Aggregate exists](https://subschema-llc.github.io/aggregate/start/why) |
| Install it on web hosting from a release ZIP, without SSH | [Release ZIP on a web host](https://subschema-llc.github.io/aggregate/install/deployment#release-zip-on-a-web-host) · [Plesk guide](https://subschema-llc.github.io/aggregate/install/plesk) |
| Install it with Docker or on my own server | [Deployment guide](https://subschema-llc.github.io/aggregate/install/deployment) |
| Choose and connect a database (MySQL, MariaDB, PostgreSQL, SQL Server, SQLite) | [Databases](https://subschema-llc.github.io/aggregate/install/databases) |
| Add tracking, a consent banner or tags to a website | [Setup and drop-ins](https://subschema-llc.github.io/aggregate/tracking/setup) · [Tracker](https://subschema-llc.github.io/aggregate/tracking/tracker) · [Tag manager](https://subschema-llc.github.io/aggregate/tracking/tag-manager) |
| Know what is stored, and when consent is needed | [Privacy and compliance](https://subschema-llc.github.io/aggregate/privacy/compliance) |
| Connect Power BI or Tableau | [Connect a BI tool](https://subschema-llc.github.io/aggregate/reporting/connect-bi) · [BI labels and glossary](https://subschema-llc.github.io/aggregate/reporting/bi-glossary) |
| Change settings in YAML or the dashboard | [Configuration](https://subschema-llc.github.io/aggregate/configure/configuration) · [Custom data model](https://subschema-llc.github.io/aggregate/configure/data-model) |
| Update an installation, or roll an update back | [Updating](https://subschema-llc.github.io/aggregate/operate/updates) |
| Look up a term | [Glossary](https://subschema-llc.github.io/aggregate/start/glossary) |

## Contribute

Aggregate is in public beta, and the most useful help right now is a
[test report](https://subschema-llc.github.io/aggregate/start/beta-testing), a fix
to a confusing documentation step, a database integration check, or an
accessibility improvement.

- [Why Aggregate exists](https://subschema-llc.github.io/aggregate/start/why): the problem, the principles, and what the project will not do.
- [Architecture tour](https://subschema-llc.github.io/aggregate/contribute/architecture): how an event travels from a web page to a BI report.
- [Design decisions](https://subschema-llc.github.io/aggregate/contribute/design-decisions): why the main choices were made and what would change them.
- [Contributing guide](https://subschema-llc.github.io/aggregate/contribute/contributing): development setup, branches, privacy rules, tests and pull requests.
- [Roadmap](https://subschema-llc.github.io/aggregate/project/roadmap): planned work and proposals to explore.

Work happens on branches from `development`, and pull requests target
`development`.

## Get help

- **Questions and ideas:** [GitHub Discussions](https://github.com/Subschema-LLC/aggregate/discussions).
- **Bugs and proposals:** [GitHub issues](https://github.com/Subschema-LLC/aggregate/issues). Please discuss a larger change in an issue before starting on it.
- **Security vulnerabilities:** report them privately, as described in the [security policy](https://subschema-llc.github.io/aggregate/project/security), not in a public issue.
- **Community expectations:** [Code of conduct](https://subschema-llc.github.io/aggregate/project/code-of-conduct).

## About this wiki

This wiki is published from
[`.github/wiki`](https://github.com/Subschema-LLC/aggregate/tree/development/.github/wiki)
in the repository, and each publish replaces it, so edits made here on GitHub are
lost. To improve a documentation page, use **Edit this page** at the bottom of
that page on the documentation site, which opens a pull request.
