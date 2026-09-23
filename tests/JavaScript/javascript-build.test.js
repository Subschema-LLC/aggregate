'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const {build, PLACEHOLDERS, DROP_INS} = require('../../scripts/build-js.cjs');
const projectDir = path.resolve(__dirname, '../..');
const pinnedVersion = require('../../package.json').devDependencies.terser;

function projectFixture(t, version) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'aggregate-js-build-project-'));
  t.after(() => fs.rmSync(directory, {recursive: true, force: true}));
  fs.writeFileSync(path.join(directory, 'package.json'), JSON.stringify({devDependencies: {terser: version}}));
  return directory;
}

test('optional build preserves licensing, dynamic config and script syntax, and detects stale outputs', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'aggregate-js-build-'));
  try {
    const outputs = await build({outputDir: directory, report: () => {}});
    assert.equal(JSON.parse(outputs.get('var/browser/manifest.json')).minifier, 'terser ' + pinnedVersion);
    const tracker = outputs.get('public/aggregate.min.js');
    assert.match(tracker, /SPDX-License-Identifier: BSD-3-Clause/);
    assert.match(tracker, /Redistribution and use in source and binary forms/);
    assert.doesNotMatch(tracker, /__AGGREGATE_/);
    assert.match(outputs.get('public/internal-traffic-marker.min.js'), /SPDX-License-Identifier: AGPL-3\.0-only/);
    assert.match(outputs.get('micro-consent-dropins/js/consent-ui.min.js'), /SPDX-License-Identifier: AGPL-3\.0-only/);

    let template = outputs.get('var/browser/aggregate.template.min.js');
    const values = ["Company'</script>\n", {storage: 'cookie', name: 'staff', value: 'true', cookieDomain: ''}, {queryParameters: {channel: 'medium'}, consentFreeProperties: ['medium']}];
    for (const [index, placeholder] of Object.values(PLACEHOLDERS).entries()) {
      assert.ok(template.includes(placeholder));
      template = template.split(placeholder).join(JSON.stringify(values[index]));
    }
    new vm.Script(template, {filename: 'configured-aggregate.min.js'});
    for (const {name, placeholder} of DROP_INS) {
      const standalone = outputs.get('public/' + name + '.min.js');
      assert.match(standalone, /SPDX-License-Identifier: AGPL-3\.0-only/);
      assert.doesNotMatch(standalone, /__AGGREGATE_/);
      const compiled = outputs.get('var/browser/' + name + '.template.min.js');
      assert.ok(compiled.includes(placeholder));
      const manifest = JSON.parse(outputs.get('var/browser/' + name + '-manifest.json'));
      const digest = (content) => require('node:crypto').createHash('sha256').update(content).digest('hex');
      assert.equal(manifest.sourceSha256, digest(fs.readFileSync(path.join(projectDir, 'public', name + '.js'))));
      assert.equal(manifest.templateSha256, digest(compiled));
      new vm.Script(compiled.split(placeholder).join(JSON.stringify({enabled: false, tags: [], namespace: "Company'</script>\n", name: 'Company'})));
    }
    for (const [filename, content] of outputs) {
      if (filename.endsWith('.js') && !filename.endsWith('.template.min.js')) new vm.Script(content, {filename});
    }

    await build({outputDir: directory, check: true, report: () => {}});
    fs.appendFileSync(path.join(directory, 'public', 'aggregate.min.js'), '// stale');
    await assert.rejects(build({outputDir: directory, check: true, report: () => {}}), /Missing or stale generated asset/);
  } finally {
    fs.rmSync(directory, {recursive: true, force: true});
  }
});

test('build requires an exact package.json minifier pin', async (t) => {
  for (const version of [undefined, null, 5, '^5.31.0', '~5.31.0', '5.x', 'latest']) {
    await t.test(String(version), async (t) => {
      await assert.rejects(build({projectDir: projectFixture(t, version), report: () => {}}), /Pin devDependencies\.terser to an exact x\.y\.z version in package\.json/);
    });
  }
});

test('build rejects an installed minifier that differs from the package.json pin', async (t) => {
  const directory = projectFixture(t, pinnedVersion);
  const installedPackage = path.join(directory, 'node_modules', 'terser');
  fs.mkdirSync(installedPackage, {recursive: true});
  fs.writeFileSync(path.join(installedPackage, 'package.json'), JSON.stringify({name: 'terser', version: '0.0.0'}));

  await assert.rejects(build({projectDir: directory, report: () => {}}), (error) => {
    assert.equal(error.message, 'Terser ' + pinnedVersion + ' is required. Run npm ci --ignore-scripts, or install that version on PATH.');
    return true;
  });
  assert.equal(fs.existsSync(path.join(directory, 'var', 'browser', 'manifest.json')), false);
});

test('build accepts the package.json pin from an optional global minifier', async (t) => {
  const directory = projectFixture(t, pinnedVersion);
  for (const source of ['public', 'templates', 'micro-consent-dropins']) {
    fs.symlinkSync(path.join(projectDir, source), path.join(directory, source), 'dir');
  }
  const bin = path.join(directory, 'bin');
  fs.mkdirSync(bin);
  const terserPackage = path.dirname(require.resolve('terser/package.json'));
  fs.symlinkSync(path.join(terserPackage, 'bin', 'terser'), path.join(bin, 'terser'));

  const originalPath = process.env.PATH;
  process.env.PATH = bin;
  try {
    const outputs = await build({projectDir: directory, outputDir: path.join(directory, 'output'), report: () => {}});
    assert.equal(JSON.parse(outputs.get('var/browser/manifest.json')).minifier, 'terser ' + pinnedVersion);
    assert.match(outputs.get('public/aggregate.min.js'), /SPDX-License-Identifier: BSD-3-Clause/);
  } finally {
    if (originalPath === undefined) delete process.env.PATH;
    else process.env.PATH = originalPath;
  }
});
