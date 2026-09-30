// Copies the repository's Markdown documentation into website/content for
// VitePress, without changing the originals:
//
//  - links between published documents become site routes (anchors kept);
//  - links to other repository files and folders open them on GitHub;
//  - images from docs/images are copied into the site; external images (badges)
//    are dropped so the site makes no third-party requests;
//  - each page gets front matter (title, description, source path);
//  - links whose target file or heading does not exist are reported, and fail
//    the run with --strict (used in CI).
//
// Usage: node scripts/sync-docs.mjs [--strict]

import { existsSync, mkdirSync, readFileSync, rmSync, statSync, copyFileSync, writeFileSync } from 'node:fs';
import { dirname, join, posix, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { editBranch, pages, repository } from '../pages.mjs';
import { githubSlug } from '../slug.mjs';

const website = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const root = resolve(website, '..');
const content = join(website, 'content');
const strict = process.argv.includes('--strict');
/** Branch for links to repository files that are not published pages: the published docs follow master. */
const sourceBranch = process.env.DOCS_SOURCE_BRANCH || 'master';

const byPath = new Map(pages.map((page) => [page.source, page]));
const problems = [];

rmSync(content, { recursive: true, force: true });
mkdirSync(join(content, 'public', 'images'), { recursive: true });
copyFileSync(join(website, 'home.md'), join(content, 'index.md'));
copyFileSync(join(website, 'favicon.svg'), join(content, 'public', 'favicon.svg'));

/** Headings of each published page, as GitHub anchors, for checking links. */
const anchors = new Map(pages.map((page) => [page.source, headingAnchors(read(page.source))]));

for (const page of pages) {
  const markdown = transform(read(page.source), page.source);
  const title = firstHeading(markdown) ?? page.label;
  const frontMatter = [
    '---',
    `title: ${yamlString(title)}`,
    `description: ${yamlString(firstParagraph(markdown))}`,
    `source: ${yamlString(page.source)}`,
    `editUrl: ${yamlString(`https://github.com/${repository}/edit/${editBranch}/${page.source}`)}`,
    '---',
    '',
  ].join('\n');
  const target = join(content, `${page.route}.md`);
  mkdirSync(dirname(target), { recursive: true });
  // v-pre: documentation shows Twig and Vue-like {{ }} text that must stay literal.
  writeFileSync(target, `${frontMatter}::: v-pre\n\n${markdown.trim()}\n\n:::\n`);
}

for (const problem of problems) {
  console.warn(`${strict ? 'error' : 'warning'}: ${problem}`);
}
console.log(`Synced ${pages.length} pages into ${relative(root, content)}${problems.length ? `, ${problems.length} link problem(s)` : ''}.`);
if (strict && problems.length > 0) {
  process.exit(1);
}

function read(path) {
  return readFileSync(join(root, path), 'utf8');
}

/** Rewrite links outside fenced code blocks. */
function transform(markdown, source) {
  const lines = markdown.split('\n');
  let fence = null;
  return lines
    .map((line) => {
      const marker = line.match(/^\s*(```+|~~~+)/);
      if (marker) {
        if (fence === null) {
          fence = marker[1][0];
        } else if (marker[1][0] === fence) {
          fence = null;
        }
        return line;
      }
      if (fence !== null) {
        return line;
      }
      return rewriteLine(line, source);
    })
    .join('\n');
}

function rewriteLine(line, source) {
  // Inline code spans stay literal, but link text may contain code: [`file`](file).
  const code = [];
  for (const span of line.matchAll(/(`+)[^`]*?\1/g)) {
    code.push([span.index, span.index + span[0].length]);
  }
  const inCode = (offset) => code.some(([start, end]) => offset >= start && offset < end);
  const outsideCode = (pattern, replace) => {
    line = line.replace(pattern, (...args) => {
      const offset = args[args.length - 2];
      return inCode(offset) ? args[0] : replace(...args);
    });
  };

  // External images, including linked badges, are removed.
  outsideCode(/\[!\[[^\]]*\]\(https?:[^)]*\)\]\([^)]*\)\s*/g, () => '');
  outsideCode(/!\[[^\]]*\]\(https?:[^)]*\)\s*/g, () => '');
  // Images first, so an image inside a link ([![alt](a.svg)](a.svg)) is rewritten too.
  outsideCode(/(!\[[^\]]*\]\()([^)\s]+)(\))/g, (match, open, target, close) => open + rewriteTarget(target, source, true) + close);
  outsideCode(/((?<!!)\[(?:[^[\]]|\[[^\]]*\])*\]\()([^)\s]+)(\))/g, (match, open, target, close) => open + rewriteTarget(target, source, false) + close);
  outsideCode(/^(\s*\[[^\]]+\]:\s*)(\S+)/, (match, open, target) => open + rewriteTarget(target, source, false));

  return line;
}

function rewriteTarget(target, source, image) {
  if (/^(?:[a-z][a-z0-9+.-]*:|#)/i.test(target)) {
    return target;
  }
  const [pathPart, anchor] = splitAnchor(target);
  const path = pathPart.startsWith('/') ? posix.normalize(pathPart.slice(1)) : posix.normalize(posix.join(posix.dirname(source), pathPart));
  const suffix = anchor ? `#${anchor}` : '';

  const page = byPath.get(path);
  if (page) {
    if (anchor && !anchors.get(path).has(anchor)) {
      problems.push(`${source}: "${target}" points to a heading that ${path} does not have`);
    }
    return `/${page.route}${suffix}`;
  }
  if (!existsSync(join(root, path)) || path.startsWith('..')) {
    problems.push(`${source}: "${target}" does not exist in the repository`);
    return target;
  }
  if (image || /\.(?:png|jpe?g|gif|svg|webp)$/i.test(path)) {
    const name = posix.basename(path);
    copyFileSync(join(root, path), join(content, 'public', 'images', name));
    return `/images/${name}`;
  }
  const kind = statSync(join(root, path)).isDirectory() ? 'tree' : 'blob';
  return `https://github.com/${repository}/${kind}/${sourceBranch}/${path}${suffix}`;
}

function splitAnchor(target) {
  const index = target.indexOf('#');
  return index === -1 ? [target, ''] : [target.slice(0, index), target.slice(index + 1)];
}

function headingAnchors(markdown) {
  const found = new Set();
  const counts = new Map();
  let fence = null;
  for (const line of markdown.split('\n')) {
    const marker = line.match(/^\s*(```+|~~~+)/);
    if (marker) {
      fence = fence === null ? marker[1][0] : marker[1][0] === fence ? null : fence;
      continue;
    }
    const heading = fence === null ? line.match(/^#{1,6}\s+(.+?)\s*#*\s*$/) : null;
    if (heading) {
      const slug = githubSlug(headingText(heading[1]));
      const seen = counts.get(slug) ?? 0;
      counts.set(slug, seen + 1);
      found.add(seen === 0 ? slug : `${slug}-${seen}`);
    }
  }
  return found;
}

/** The text GitHub renders for a heading's Markdown. */
function headingText(markdown) {
  return markdown
    .split(/(`+[^`]*`+)/)
    .map((part, index) => (index % 2 === 1 ? part.replace(/^`+|`+$/g, '') : part.replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/(\*\*|\*)(.+?)\1/g, '$2').replace(/<[^>]+>/g, '')))
    .join('');
}

function firstHeading(markdown) {
  const match = markdown.match(/^#\s+(.+)$/m);
  return match ? headingText(match[1]).trim() : null;
}

/** The first prose paragraph after the title, as plain text, for search results and link previews. */
function firstParagraph(markdown) {
  const blocks = markdown.split(/\n\s*\n/).map((block) => block.trim());
  for (const block of blocks) {
    if (block === '' || /^(?:#|\||>|```|~~~|<|[-*] |\d+\. |!\[|\[!\[)/.test(block)) {
      continue;
    }
    // Skip link-only navigation lines such as "[A](a.md) · [B](b.md)".
    const text = headingText(block.replace(/\s+/g, ' ')).trim();
    if (/^(?:\[[^\]]*\]\([^)]*\)\s*[·|]?\s*)+$/.test(block.replace(/\s+/g, ' ')) || text.length < 40) {
      continue;
    }
    return text.length > 200 ? `${text.slice(0, 197).replace(/\s+\S*$/, '')}…` : text;
  }
  return '';
}

function yamlString(value) {
  return JSON.stringify(value);
}
