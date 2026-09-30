---
layout: home
title: Aggregate Analytics documentation
titleTemplate: false

hero:
  name: Aggregate Analytics
  text: Documentation
  tagline: Self-hosted, privacy-focused analytics that feeds the BI tools you already use. Install it, collect with or without consent, and report in Power BI or Tableau.
  actions:
    - theme: brand
      text: Install from a release ZIP
      link: /install/deployment#release-zip-on-a-web-host
    - theme: alt
      text: Why Aggregate exists
      link: /start/why
    - theme: alt
      text: Source on GitHub
      link: https://github.com/Subschema-LLC/aggregate

features:
  - title: Install
    details: A release ZIP and the browser setup page, Docker, or a native server. MySQL, MariaDB, PostgreSQL, SQL Server or SQLite.
    link: /install/deployment
    linkText: Deployment guide
  - title: Hosting panels
    details: Step-by-step installation with a hosting panel's file manager, without SSH.
    link: /install/plesk
    linkText: Plesk guide
  - title: Track websites
    details: Add the tracker, the consent banner and the tag manager to a website, and verify it before real traffic.
    link: /tracking/setup
    linkText: Setup and drop-ins
  - title: Privacy and consent
    details: Anonymous and enhanced measurement, what each stores, consent withdrawal, and small-number suppression.
    link: /privacy/compliance
    linkText: Privacy and compliance
  - title: Report in your BI tool
    details: Stable reporting views, safe queries, labels for codes, and view-only database access for Power BI or Tableau.
    link: /reporting/connect-bi
    linkText: Connect a BI tool
  - title: Configure
    details: YAML and dashboard settings, the custom data model, and feature flags.
    link: /configure/configuration
    linkText: Configuration
  - title: Operate
    details: One-click updates from signed releases, rollback, archiving and retention.
    link: /operate/updates
    linkText: Updating
  - title: Contribute
    details: Why the project exists, how an event flows through the code, the decisions behind it, and how to send a change.
    link: /contribute/architecture
    linkText: Architecture tour
---

## Common questions

- [How do I install Aggregate on shared hosting without SSH?](/install/deployment#release-zip-on-a-web-host)
- [Which databases are supported, and how do I connect SQL Server?](/install/databases)
- [How do I add tracking to a website?](/tracking/setup)
- [Does anonymous-mode measurement require consent?](/privacy/compliance#does-anonymous-mode-measurement-require-consent)
- [How do I connect Power BI or Tableau?](/reporting/connect-bi)
- [How do I give Power BI or Tableau read-only access?](/reporting/bi-glossary#view-only-grants)
- [How do I update an installation, and what is kept?](/operate/updates)
- [What happens to data when someone withdraws consent?](/privacy/compliance#does-withdrawing-consent-delete-past-data)
- [Why doesn't Aggregate count unique visitors?](/contribute/design-decisions#no-identifier-in-anonymous-mode-not-even-a-rotating-hash)
- [I want to contribute. Where do I start?](/contribute/contributing)

Search every page with the search box at the top, or press <kbd>/</kbd> or
<kbd>Ctrl</kbd> <kbd>K</kbd>. Search runs in your browser and needs no account. This
site is built from the Markdown files in the repository's `master` branch, so it
describes the current release.
