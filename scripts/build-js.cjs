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
// Tracker builds by the code they keep. The server sends a smaller build only
// when the page speed setting allows it and the served settings mean the left
// out code could never run: page depth turned off, or the strict profile on.
// The switches are declared `var withX = true;` in the source and only ever
// test an if statement; BrowserScriptCompactor prunes the same way in PHP.
const TRACKER_BUILDS = {
  full: {withPageDepth: true, withStandardProfile: true},
  'without-page-depth': {withPageDepth: false, withStandardProfile: true},
  strict: {withPageDepth: false, withStandardProfile: false}
};
// Tracker object members that keep their names in every build: settings a page
// can also pass in, and the methods exposed on window[namespace].
const KEPT_TRACKER_PROPERTIES = new Set(['config', 'consent', 'emit', 'trackView', 'setConsent', 'toString', 'valueOf', 'toJSON', 'then', 'handleEvent']);
const DROP_INS = [
  {name: 'consent', variable: 'consentConfig', placeholder: '__AGGREGATE_CONSENT_CONFIG__',
    stylesheet: {source: 'consent.css', variable: 'consentStyles', placeholder: '__AGGREGATE_CONSENT_STYLES__'}},
  {name: 'tag-manager', variable: 'tagManagerConfig', placeholder: '__AGGREGATE_TAG_MANAGER__',
    // Custom JavaScript tags are compiled into the served script in place of this.
    extra: {variable: 'customScripts', placeholder: '__AGGREGATE_CUSTOM_SCRIPTS__'}}
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
    acorn: createRequire(packagePath)('acorn'),
    // defines: constants that replace free names, for the tracker's build
    // switches. properties: tracker-only member names to shorten.
    minify: async function (source, {defines = {}, properties = []} = {}) {
      const result = await terser.minify(source, {
        compress: {global_defs: defines},
        mangle: properties.length ? {properties: {regex: new RegExp('^(?:' + properties.join('|') + ')$'), builtins: true}} : true,
        ecma: 2018,
        format: {comments: /^!|@preserve|@license|@cc_on/, inline_script: true}
      });
      return result.code.trimEnd() + '\n';
    }
  };
}

/** The tracker source without its build-switch declarations; the builds define them. */
function withoutSwitchDeclarations(source) {
  for (const name of Object.keys(TRACKER_BUILDS.full)) {
    const declaration = new RegExp('^  var ' + name + ' = true;\\n', 'm');
    if (source.split(declaration).length !== 2) throw new Error('Tracker build switch must be declared once as var ' + name + ' = true;');
    source = source.replace(declaration, '');
  }
  return source;
}

/**
 * Members of the tracker's internal Analytics object that can take short names
 * in the minified builds. A name qualifies only when the parsed program uses
 * it as a member of that object alone (this.name, Analytics.name, or
 * tracker.name, a local alias for this), never as a key of another object and
 * never as a string, so no page setting, payload field or other script can
 * depend on it. Uses acorn, the parser Terser itself depends on.
 */
function trackerPropertyNames(source, acorn) {
  const program = acorn.parse(source, {ecmaVersion: 'latest', sourceType: 'script'});
  const nodes = [];
  (function walk(node) {
    if (Array.isArray(node)) return node.forEach(walk);
    if (!node || typeof node.type !== 'string') return;
    nodes.push(node);
    for (const key of Object.keys(node)) {
      if (node[key] && typeof node[key] === 'object') walk(node[key]);
    }
  })(program);
  const declaration = nodes.find((node) => node.type === 'VariableDeclarator' && node.id.name === 'Analytics'
    && node.init && node.init.type === 'ObjectExpression');
  if (!declaration) throw new Error('Tracker object not found in public/aggregate.js.');
  if (nodes.some((node) => node.type === 'VariableDeclarator' && node.id.name === 'tracker' && !(node.init && node.init.type === 'ThisExpression'))) {
    throw new Error('In public/aggregate.js, a variable named tracker may only hold this, the tracker object.');
  }
  const own = new Set(declaration.init.properties);
  const keyName = (node) => node.computed ? null : (node.key.type === 'Identifier' ? node.key.name : String(node.key.value));
  const excluded = new Set(KEPT_TRACKER_PROPERTIES);
  for (const node of nodes) {
    if (node.type === 'Literal' && typeof node.value === 'string') excluded.add(node.value);
    if (node.type === 'TemplateElement') excluded.add(node.value.cooked);
    if ((node.type === 'Property' || node.type === 'MethodDefinition') && !own.has(node)) excluded.add(keyName(node));
    if (node.type === 'MemberExpression' && !node.computed && !(node.object.type === 'ThisExpression'
      || (node.object.type === 'Identifier' && ['Analytics', 'tracker'].includes(node.object.name)))) excluded.add(node.property.name);
  }
  return declaration.init.properties.map(keyName).filter((name) => name !== null && !excluded.has(name));
}

async function build({projectDir = path.resolve(__dirname, '..'), outputDir = projectDir, check = false, report = console.log} = {}) {
  const {version, minify, acorn} = minifier(projectDir);
  const tracker = fs.readFileSync(path.join(projectDir, 'public', 'aggregate.js'), 'utf8');
  const trackerBuildSource = withoutSwitchDeclarations(tracker);
  const properties = trackerPropertyNames(tracker, acorn);
  let template = trackerBuildSource;
  for (const [variable, placeholder] of Object.entries(PLACEHOLDERS)) {
    const declaration = new RegExp('^  var ' + variable + ' = .+;$', 'm');
    if (!declaration.test(template)) throw new Error('Tracker configuration declaration not found: ' + variable);
    template = template.replace(declaration, '  var ' + variable + ' = ' + placeholder + ';');
  }
  const trackerTemplates = new Map();
  for (const [name, defines] of Object.entries(TRACKER_BUILDS)) {
    const minifiedTemplate = await minify(template, {defines, properties});
    for (const placeholder of Object.values(PLACEHOLDERS)) {
      if (minifiedTemplate.split(placeholder).length !== 2) throw new Error('The ' + name + ' tracker template must contain ' + placeholder + ' exactly once.');
    }
    for (const switchName of Object.keys(defines)) {
      if (minifiedTemplate.includes(switchName)) throw new Error('The ' + name + ' tracker build still refers to ' + switchName + '.');
    }
    trackerTemplates.set(name, minifiedTemplate);
  }
  const minifiedTemplate = trackerTemplates.get('full');

  const marker = fs.readFileSync(path.join(projectDir, 'templates', 'internal_traffic', 'marker.js.twig'), 'utf8');
  const outputs = new Map([
    // Static copies receive no server settings, so they keep every feature.
    ['public/aggregate.min.js', await minify(trackerBuildSource, {defines: TRACKER_BUILDS.full, properties})],
    ['var/browser/aggregate.template.min.js', minifiedTemplate],
    ['public/internal-traffic-marker.min.js', await minify(AGPL_NOTICE + marker)]
  ]);
  const builds = {};
  for (const [name, content] of trackerTemplates) {
    if (name === 'full') continue;
    const file = 'aggregate-' + name + '.template.min.js';
    outputs.set('var/browser/' + file, content);
    builds[name] = {file, templateSha256: digest(content)};
  }
  for (const {name, variable, placeholder, stylesheet, extra} of DROP_INS) {
    const source = fs.readFileSync(path.join(projectDir, 'public', name + '.js'), 'utf8');
    const declaration = new RegExp('^  var ' + variable + ' = .+;$', 'm');
    if (!declaration.test(source)) throw new Error('Drop-in configuration declaration not found: ' + variable);
    let templateSource = source.replace(declaration, '  var ' + variable + ' = ' + placeholder + ';');
    if (extra) {
      const extraDeclaration = new RegExp('^  var ' + extra.variable + ' = .+;$', 'm');
      if (!extraDeclaration.test(templateSource)) throw new Error('Drop-in declaration not found: ' + extra.variable);
      templateSource = templateSource.replace(extraDeclaration, '  var ' + extra.variable + ' = ' + extra.placeholder + ';');
    }
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
    // The server replaces each placeholder once; a copied placeholder would duplicate code.
    if (extra && template.split(extra.placeholder).length !== 2) throw new Error('The minified template must contain ' + extra.placeholder + ' exactly once.');
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
  const standalone = fs.readFileSync(path.join(projectDir, 'micro-consent-dropins/js/consent-ui.js'), 'utf8');
  const adapter = fs.readFileSync(path.join(projectDir, 'micro-consent-dropins/js/aggregate-consent.js'), 'utf8');
  const styles = fs.readFileSync(path.join(projectDir, 'micro-consent-dropins/css/consent-ui.css'), 'utf8');
  const styleDeclaration = '  var microConsentStyles = null;';
  if (standalone.split(styleDeclaration).length !== 2 || !styles.trim() || !adapter.trim()) {
    throw new Error('Standalone consent sources or stylesheet declaration are invalid.');
  }
  const standaloneTemplate = await minify(AGPL_NOTICE + 'window.MicroConsentConfig = __MICRO_CONSENT_CONFIG__;\n'
    + standalone.replace(styleDeclaration, '  var microConsentStyles = __MICRO_CONSENT_STYLES__;') + '\n' + adapter);
  for (const placeholder of ['__MICRO_CONSENT_CONFIG__', '__MICRO_CONSENT_STYLES__']) {
    if (!standaloneTemplate.includes(placeholder)) throw new Error('Minifier removed standalone placeholder: ' + placeholder);
  }
  outputs.set('var/browser/standalone-consent.template.min.js', standaloneTemplate);
  outputs.set('var/browser/standalone-consent-manifest.json', JSON.stringify({
    format: 1, sourceSha256: digest(standalone), adapterSha256: digest(adapter),
    stylesheetSha256: digest(styles), templateSha256: digest(standaloneTemplate), minifier: 'terser ' + version
  }, null, 2) + '\n');
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
    builds,
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

module.exports = {build, PLACEHOLDERS, DROP_INS, TRACKER_BUILDS, trackerPropertyNames};
