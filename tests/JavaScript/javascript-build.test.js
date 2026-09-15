'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const {build, PLACEHOLDERS} = require('../../scripts/build-js.cjs');

test('optional build preserves licensing, dynamic config and script syntax, and detects stale outputs', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'aggregate-js-build-'));
  try {
    const outputs = await build({outputDir: directory, report: () => {}});
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
