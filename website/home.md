---
layout: home
title: Aggregate Analytics documentation
titleTemplate: false

hero:
  name: Aggregate Analytics
  text: Headless, AI-ready web analytics
  tagline: Count what happens on your websites, not who did it. Aggregate keeps the results in your own database as privacy-protected SQL views that Power BI, Tableau or your AI assistant can query directly.
  actions:
    - theme: brand
      text: Install from a release ZIP
      link: /install/deployment#release-zip-on-a-web-host
    - theme: alt
      text: Query with BI or AI
      link: /reporting/connect-bi
    - theme: alt
      text: Why Aggregate exists
      link: /start/why

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
  - title: Ask your BI tool or AI assistant
    details: Stable, documented reporting views with small counts withheld. Power BI, Tableau and AI assistants use the same read-only account and never see a visitor record.
    link: /reporting/connect-bi
    linkText: Connect BI and AI tools
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
- [Can my server send events instead of a script?](/tracking/server-side)
- [Does anonymous-mode measurement require consent?](/privacy/compliance#does-anonymous-mode-measurement-require-consent)
- [How do I connect Power BI or Tableau?](/reporting/connect-bi)
- [Can an AI assistant query my analytics safely?](/reporting/connect-bi#connect-an-ai-assistant)
- [How do I give Power BI or Tableau read-only access?](/reporting/bi-glossary#view-only-grants)
- [How do I update an installation, and what is kept?](/operate/updates)
- [What happens to data when someone withdraws consent?](/privacy/compliance#does-withdrawing-consent-delete-past-data)
- [Why doesn't Aggregate count unique visitors?](/contribute/design-decisions#no-identifier-in-anonymous-mode-not-even-a-rotating-hash)
- [I want to contribute. Where do I start?](/contribute/contributing)

Search every page with the search box at the top, or press <kbd>/</kbd> or
<kbd>Ctrl</kbd> <kbd>K</kbd>. Search runs in your browser and needs no account. This
site is built from the Markdown files in the repository's `master` branch, so it
describes the current release.
