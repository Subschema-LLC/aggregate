// The documentation site's structure. Each entry publishes one Markdown file from
// the repository at a stable route; the repository files stay the only source.
// Files that are not listed here are linked on GitHub instead.

export const repository = 'Subschema-LLC/aggregate';
/** Branch that "Edit this page" links open: contributions target development. */
export const editBranch = 'development';

export const sections = [
  {
    text: 'Get started',
    items: [
      { source: 'README.md', route: 'start/overview', label: 'Overview' },
      { source: 'docs/WHY.md', route: 'start/why', label: 'Why Aggregate exists' },
      { source: 'docs/GLOSSARY.md', route: 'start/glossary', label: 'Glossary' },
      { source: 'docs/BETA-TESTING.md', route: 'start/beta-testing', label: 'Beta testing' },
    ],
  },
  {
    text: 'Install',
    items: [
      { source: 'DEPLOYMENT.md', route: 'install/deployment', label: 'Deployment guide' },
      { source: 'PLESK-DEPLOYMENT.md', route: 'install/plesk', label: 'Plesk' },
      { source: 'docs/DATABASE.md', route: 'install/databases', label: 'Databases' },
    ],
  },
  {
    text: 'Track websites',
    items: [
      { source: 'docs/SETUP.md', route: 'tracking/setup', label: 'Setup and drop-ins' },
      { source: 'docs/TRACKING.md', route: 'tracking/tracker', label: 'Tracker' },
      { source: 'docs/SERVER-SIDE.md', route: 'tracking/server-side', label: 'Server-side collection' },
      { source: 'docs/TAG-MANAGER.md', route: 'tracking/tag-manager', label: 'Tag manager' },
      { source: 'docs/CONSENT-REGIONS.md', route: 'tracking/consent-regions', label: 'Regional consent examples' },
      { source: 'micro-consent-dropins/README.md', route: 'tracking/standalone-consent', label: 'Standalone consent banner' },
      { source: 'docs/EVENT-EXAMPLES.md', route: 'tracking/event-examples', label: 'Event examples' },
      { source: 'docs/JS-BUILD.md', route: 'tracking/js-build', label: 'JavaScript build' },
    ],
  },
  {
    text: 'Configure',
    items: [
      { source: 'docs/CONFIGURATION.md', route: 'configure/configuration', label: 'Configuration' },
      { source: 'docs/DATA-MODEL.md', route: 'configure/data-model', label: 'Custom data model' },
      { source: 'docs/FEATURE-FLAGS.md', route: 'configure/feature-flags', label: 'Feature flags' },
    ],
  },
  {
    text: 'Privacy and reporting',
    items: [
      { source: 'docs/PRIVACY-COMPLIANCE.md', route: 'privacy/compliance', label: 'Privacy and compliance' },
      { source: 'docs/BI-CONNECTION.md', route: 'reporting/connect-bi', label: 'Connect BI and AI tools' },
      { source: 'docs/BI-GLOSSARY.md', route: 'reporting/bi-glossary', label: 'BI labels and glossary' },
    ],
  },
  {
    text: 'Operate',
    items: [
      { source: 'docs/UPDATES.md', route: 'operate/updates', label: 'Updating' },
      { source: 'docs/RELEASES.md', route: 'operate/releases', label: 'Signed release packages' },
    ],
  },
  {
    text: 'Contribute',
    items: [
      { source: 'CONTRIBUTING.md', route: 'contribute/contributing', label: 'Contributing' },
      { source: 'docs/ARCHITECTURE.md', route: 'contribute/architecture', label: 'Architecture tour' },
      { source: 'docs/DESIGN-DECISIONS.md', route: 'contribute/design-decisions', label: 'Design decisions' },
      { source: 'templates/README.md', route: 'contribute/ui-templates', label: 'UI templates' },
      { source: 'AGENTS.md', route: 'contribute/coding-agents', label: 'Guide for coding agents' },
    ],
  },
  {
    text: 'Project',
    items: [
      { source: 'ROADMAP.md', route: 'project/roadmap', label: 'Roadmap' },
      { source: 'SECURITY.md', route: 'project/security', label: 'Security policy' },
      { source: 'CODE_OF_CONDUCT.md', route: 'project/code-of-conduct', label: 'Code of conduct' },
      { source: 'docs/PUBLIC-RELEASE.md', route: 'project/public-release', label: 'Public release preparation' },
    ],
  },
];

export const pages = sections.flatMap((section) => section.items.map((item) => ({ ...item, section: section.text })));
