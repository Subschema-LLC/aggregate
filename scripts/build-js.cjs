#!/usr/bin/env node
'use strict';

// SPDX-License-Identifier: AGPL-3.0-only
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {createRequire} = require('node:module');

const PLACEHOLDERS = {
  namespace: '__AGGREGATE_NAMESPACE__',
  internalTrafficDefaults: '__AGGREGATE_INTERNAL_TRAFFIC__',
  customDataDefaults: '__AGGREGATE_CUSTOM_DATA__',
  collectionDefaults: '__AGGREGATE_COLLECTION__'
};
const DROP_INS = [
  {name: 'consent', variable: 'consentConfig', placeholder: '__AGGREGATE_CONSENT_CONFIG__',
    stylesheet: {source: 'consent.css', variable: 'consentStyles', placeholder: '__AGGREGATE_CONSENT_STYLES__'}},
  {name: 'tag-manager', variable: 'tagManagerConfig', placeholder: '__AGGREGATE_TAG_MANAGER__'}
];
const AGPL_NOTICE = '/*! Aggregate Analytics — SPDX-License-Identifier: AGPL-3.0-only; see LICENSE in the source repository. */\n';

function digest(content) {
  return crypto.createHash('sha256').update(content).digest('hex');
}

function minifier(projectDir) {
  const projectPackagePath = path.join(projectDir, 'package.json');
  const version = JSON.parse(fs.readFileSync(projectPackagePath, 'utf8')).devDependencies?.terser;
  if (typeof version !== 'string' || !/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(version)) {
    throw new Error('Pin devDependencies.terser to an exact x.y.z version in package.json.');
  }
  const requireFromProject = createRequire(projectPackagePath);
  let packagePath;
  try {
    packagePath = requireFromProject.resolve('terser/package.json');
  } catch (error) {
    // A matching global CLI is an optional offline build fallback. Load its
    // package directly; serving scripts never launches Node or the minifier.
    for (const directory of (process.env.PATH || '').split(path.delimiter)) {
      try {
        const executable = fs.realpathSync(path.join(directory, 'terser'));
        const candidate = path.resolve(path.dirname(executable), '..', 'package.json');
        if (JSON.parse(fs.readFileSync(candidate, 'utf8')).name === 'terser') {
          packagePath = candidate;
          break;
        }
      } catch (error) {}
    }
  }
  if (!packagePath || JSON.parse(fs.readFileSync(packagePath, 'utf8')).version !== version) {
    throw new Error('Terser ' + version + ' is required. Run npm ci --ignore-scripts, or install that version on PATH.');
  }
  const terser = require(path.dirname(packagePath));

  return {
    version,
    minify: async function (source) {
      const result = await terser.minify(source, {
        compress: true,
        mangle: true,
        ecma: 2018,
        format: {comments: /^!|@preserve|@license|@cc_on/, inline_script: true}
      });
      return result.code.trimEnd() + '\n';
    }
  };
}

async function build({projectDir = path.resolve(__dirname, '..'), outputDir = projectDir, check = false, report = console.log} = {}) {
  const {version, minify} = minifier(projectDir);
  const tracker = fs.readFileSync(path.join(projectDir, 'public', 'aggregate.js'), 'utf8');
  let template = tracker;
  for (const [variable, placeholder] of Object.entries(PLACEHOLDERS)) {
    const declaration = new RegExp('^  var ' + variable + ' = .+;$', 'm');
    if (!declaration.test(template)) throw new Error('Tracker configuration declaration not found: ' + variable);
    template = template.replace(declaration, '  var ' + variable + ' = ' + placeholder + ';');
  }
  const minifiedTemplate = await minify(template);
  for (const placeholder of Object.values(PLACEHOLDERS)) {
    if (!minifiedTemplate.includes(placeholder)) throw new Error('Minifier removed a dynamic tracker placeholder: ' + placeholder);
  }

  const marker = fs.readFileSync(path.join(projectDir, 'templates', 'internal_traffic', 'marker.js.twig'), 'utf8');
  const outputs = new Map([
    ['public/aggregate.min.js', await minify(tracker)],
    ['var/browser/aggregate.template.min.js', minifiedTemplate],
    ['public/internal-traffic-marker.min.js', await minify(AGPL_NOTICE + marker)]
  ]);
  for (const {name, variable, placeholder, stylesheet} of DROP_INS) {
    const source = fs.readFileSync(path.join(projectDir, 'public', name + '.js'), 'utf8');
    const declaration = new RegExp('^  var ' + variable + ' = .+;$', 'm');
    if (!declaration.test(source)) throw new Error('Drop-in configuration declaration not found: ' + variable);
    let templateSource = source.replace(declaration, '  var ' + variable + ' = ' + placeholder + ';');
    const stylesheetManifest = {};
    if (stylesheet) {
      const styles = fs.readFileSync(path.join(projectDir, 'public', stylesheet.source), 'utf8');
      if (!styles.trim()) throw new Error('Drop-in stylesheet is empty: ' + stylesheet.source);
      const stylesDeclaration = new RegExp('^  var ' + stylesheet.variable + ' = null;$', 'm');
      if (!stylesDeclaration.test(templateSource)) throw new Error('Drop-in stylesheet declaration not found: ' + stylesheet.variable);
      templateSource = templateSource.replace(stylesDeclaration, '  var ' + stylesheet.variable + ' = ' + stylesheet.placeholder + ';');
      stylesheetManifest.stylesheetSha256 = digest(styles);
    }
    const template = await minify(templateSource);
    if (!template.includes(placeholder)) throw new Error('Minifier removed a dynamic drop-in placeholder: ' + placeholder);
    if (stylesheet && !template.includes(stylesheet.placeholder)) throw new Error('Minifier removed a dynamic drop-in stylesheet: ' + stylesheet.placeholder);
    outputs.set('public/' + name + '.min.js', await minify(source));
    outputs.set('var/browser/' + name + '.template.min.js', template);
    outputs.set('var/browser/' + name + '-manifest.json', JSON.stringify({
      format: 1,
      sourceSha256: digest(source),
      ...stylesheetManifest,
      templateSha256: digest(template),
      minifier: 'terser ' + version
    }, null, 2) + '\n');
  }
  for (const entry of fs.readdirSync(path.join(projectDir, 'micro-consent-dropins', 'js')).sort()) {
    if (!entry.endsWith('.js') || entry.endsWith('.min.js')) continue;
    const source = fs.readFileSync(path.join(projectDir, 'micro-consent-dropins', 'js', entry), 'utf8');
    outputs.set('micro-consent-dropins/js/' + entry.replace(/\.js$/, '.min.js'), await minify(AGPL_NOTICE + source));
    if (!source.trim()) report('Empty source: micro-consent-dropins/js/' + entry + ' (no behavior added).');
  }
  // Publish the manifest last. The controller verifies both hashes before use,
  // so interrupted builds or source changes safely fall back to current source.
  outputs.set('var/browser/manifest.json', JSON.stringify({
    format: 1,
    sourceSha256: digest(tracker),
    templateSha256: digest(minifiedTemplate),
    minifier: 'terser ' + version
  }, null, 2) + '\n');

  for (const [relative, content] of outputs) {
    const destination = path.join(outputDir, relative);
    if (check) {
      if (!fs.existsSync(destination) || fs.readFileSync(destination, 'utf8') !== content) {
        throw new Error('Missing or stale generated asset: ' + relative + '. Run npm run build:js.');
      }
    } else {
      fs.mkdirSync(path.dirname(destination), {recursive: true});
      const temporary = destination + '.tmp-' + process.pid;
      fs.writeFileSync(temporary, content);
      fs.renameSync(temporary, destination);
    }
    report((check ? 'Checked ' : 'Built ') + relative + ' (' + Buffer.byteLength(content) + ' bytes)');
  }
  return outputs;
}

if (require.main === module) {
  try {
    if (process.argv.slice(2).some((argument) => argument !== '--check')) throw new Error('Usage: node scripts/build-js.cjs [--check]');
    build({check: process.argv.includes('--check')}).catch((error) => {
      console.error(error.message);
      process.exitCode = 1;
    });
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}

module.exports = {build, PLACEHOLDERS, DROP_INS};
