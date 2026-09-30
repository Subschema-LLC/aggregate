// GitHub's heading anchors, so links written for GitHub (FILE.md#some-heading)
// keep working on the site. Used by the Markdown renderer and the link check.
// Lowercase; drop everything except letters, marks, numbers, connector
// punctuation (such as _), hyphens and spaces; turn spaces into hyphens.

export function githubSlug(text) {
  return text
    .trim()
    .toLowerCase()
    .replace(/[^\p{L}\p{M}\p{N}\p{Pc}\- ]/gu, '')
    .replace(/ /g, '-');
}
