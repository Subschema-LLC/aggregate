# Roadmap

This roadmap describes the current direction of Aggregate. Scope and priorities may change; these items have no promised release dates. Discuss proposals in [GitHub issues](https://github.com/Subschema-LLC/aggregate/issues) before starting a substantial contribution, and follow the [contribution guide](CONTRIBUTING.md).

## Planned work

- **Apply verified release packages automatically.** Signed production ZIPs, release discovery, and offline package verification are available. Applying an update still requires the [manual deployment steps](docs/RELEASES.md#install-or-deploy-a-verified-package). The next phase needs a resumable update job, maintenance and worker coordination, preservation of configuration and data, migrations, activation, health checks, and separate recovery procedures for application files and the database.
- **Website-specific configuration and data models.** Allow collection settings and custom-property models to vary by registered website. These settings currently use the deployment's active aggregate YAML configuration; website registration already manages domains and ingestion tokens separately. See the [configuration reference](docs/CONFIGURATION.md) and [data model guide](docs/DATA-MODEL.md) for current behavior.
- **UI Enhancements** We need to improve the user interface for better usability and accessibility. This also includes organization and content in addition to look and feel.
- **Generate example JSON structures for events based on the data model.** This will help users understand the structure of events and how to format them correctly when implementing.
- **Headless for AI Ready Data** Consider documentation and features that make the views AI ready for MCPs and data analysis and charts without a BI tool.
- **MCP Spec / Server** Add easy configuration for MCPs to use the data model and views without a BI tool.
- **Sample Python Notebooks for AI Ready Data** Provide sample Python notebooks that demonstrate how to use the data model and views for AI-ready data analysis and charts without a BI tool.

## Available foundations

- [Organization traffic markers](docs/PRIVACY-COMPLIANCE.md#organization-traffic), with YAML/UI settings and shareable browser setup. Filtering uses retained event JSON; grouped BI views and archives do not retain the marker.
- [Custom data models](docs/DATA-MODEL.md), including UTM/query mappings, per-property consent settings, downloadable YAML, and private reporting columns. All UTMs require consent by default; anonymous attribution should use at most broad `utm_medium` values, with documented warnings for overrides.
- [Optional JavaScript minification](docs/JS-BUILD.md) for tracker, organization-marker, and drop-in scripts.
- [Git source updates](DEPLOYMENT.md#updates) and [signed release packages](docs/RELEASES.md), with dashboard/CLI version checks and configurable update branches defaulting to `master`.

The [README](README.md) covers the wider feature set and privacy tradeoffs. The [release history](https://github.com/Subschema-LLC/aggregate/releases) records published packages.
