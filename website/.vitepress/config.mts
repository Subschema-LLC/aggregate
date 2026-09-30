import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitepress';
import { pages, repository, sections } from '../pages.mjs';
import { githubSlug } from '../slug.mjs';

const root = fileURLToPath(new URL('../..', import.meta.url));

// GitHub Pages serves this repository's site at /aggregate/. Set DOCS_BASE=/
// when publishing on a custom domain such as docs.example.com.
const base = process.env.DOCS_BASE || '/aggregate/';

export default defineConfig({
  title: 'Aggregate Analytics',
  titleTemplate: ':title · Aggregate docs',
  description: 'Documentation for Aggregate Analytics: self-hosted, privacy-focused analytics for BI tools.',
  lang: 'en-US',
  base,
  srcDir: 'content',
  cleanUrls: true,
  lastUpdated: true,
  // The site makes no requests to other hosts: no web fonts from CDNs, no
  // analytics, and search runs in the browser from a local index.
  head: [
    ['meta', { name: 'referrer', content: 'no-referrer' }],
    ['link', { rel: 'icon', type: 'image/svg+xml', href: `${base}favicon.svg` }],
  ],
  markdown: {
    // Match GitHub's heading anchors so links written for GitHub keep working.
    anchor: { slugify: githubSlug },
    languageAlias: { cron: 'bash' },
  },
  // Examples mention local development addresses such as http://localhost:9001.
  ignoreDeadLinks: 'localhostLinks',
  themeConfig: {
    logo: { src: '/favicon.svg', alt: '' },
    siteTitle: 'Aggregate docs',
    nav: [
      { text: 'Install', link: '/install/deployment', activeMatch: '^/install/' },
      { text: 'Track', link: '/tracking/setup', activeMatch: '^/tracking/' },
      { text: 'Privacy', link: '/privacy/compliance', activeMatch: '^/(privacy|reporting)/' },
      { text: 'Operate', link: '/operate/updates', activeMatch: '^/operate/' },
      { text: 'Contribute', link: '/contribute/contributing', activeMatch: '^/contribute/' },
      { text: 'Roadmap', link: '/project/roadmap' },
    ],
    sidebar: sections.map((section) => ({
      text: section.text,
      collapsed: false,
      items: section.items.map((item) => ({ text: item.label, link: `/${item.route}` })),
    })),
    outline: { level: [2, 3], label: 'On this page' },
    search: {
      provider: 'local',
      options: { detailedView: 'auto' },
    },
    editLink: {
      // Pages are copies; edits belong in the repository file they came from.
      // This function runs in the browser, so the URL comes from front matter.
      pattern: ({ frontmatter }) => frontmatter.editUrl,
      text: 'Edit this page on GitHub',
    },
    socialLinks: [{ icon: 'github', link: `https://github.com/${repository}` }],
    footer: {
      message: 'Server and documentation licensed under AGPL-3.0; the tracker under BSD-3-Clause.',
      copyright: 'Aggregate Analytics by Subschema',
    },
    docFooter: { prev: 'Previous', next: 'Next' },
    lastUpdated: { text: 'Source last changed' },
  },
  transformPageData(pageData) {
    // Pages are generated copies, so take the date from the repository file's history.
    const source = pageData.frontmatter.source;
    if (typeof source === 'string' && pages.some((page) => page.source === source)) {
      try {
        const timestamp = execFileSync('git', ['log', '-1', '--format=%ct', '--', source], { cwd: root, encoding: 'utf8' }).trim();
        pageData.lastUpdated = timestamp ? Number(timestamp) * 1000 : undefined;
      } catch {
        pageData.lastUpdated = undefined;
      }
    }
  },
});
