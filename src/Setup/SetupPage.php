<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * The HTML of the browser setup page (FirstRunSetup). Like FirstRunSetup it
 * loads before Composer, so it uses nothing but plain PHP and that class's
 * constants. Every value from the request or the server is escaped here.
 */
final class SetupPage
{
    /**
     * @param list<array{label: string, status: string, detail: string}>                                                 $checks
     * @param array{errors: array<string, string>, detail: ?string, values: array<string, string>, verified: bool} $state
     * @param string                                                                                                     $base     the application's base path, '' at the web root
     * @param string                                                                                                     $codeFile where the setup code file is, as shown to the visitor
     * @param string                                                                                                     $nonce    the Content-Security-Policy nonce for the inline script
     */
    public static function html(array $checks, array $state, bool $blocked, ?string $codeProblem, string $csrf, string $base, string $codeFile, string $nonce): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $values = $state['values'];
        $errors = $state['errors'];
        $product = $e(FirstRunSetup::PRODUCT_NAME);
        $base = $e($base);
        $installGuide = $e(FirstRunSetup::DOCUMENTATION_URL.'install/deployment');

        $icons = ['ok' => '✓', 'warn' => '!', 'fail' => '✕'];
        $statusText = ['ok' => 'OK', 'warn' => 'Warning', 'fail' => 'Needs fixing'];
        $rows = '';
        foreach ($checks as $check) {
            $rows .= '<li class="check '.$check['status'].'"><span class="icon" aria-hidden="true">'.$icons[$check['status']].'</span>'
                .'<span><strong>'.$e($check['label']).'</strong> <span class="sr">('.$statusText[$check['status']].')</span><br>'
                .$e($check['detail']).'</span></li>';
        }
        $failures = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail'));
        $summary = $failures > 0
            ? $failures.' '.($failures === 1 ? 'problem needs' : 'problems need').' fixing. Fix '.($failures === 1 ? 'it' : 'them').', then reload this page.'
            : 'The server is ready.';
        $checksOpen = $failures > 0 || array_filter($checks, static fn (array $check): bool => $check['status'] === 'warn') !== [] ? ' open' : '';

        $formError = isset($errors['form']) ? '<p class="error" role="alert">'.$e($errors['form']).'</p>' : '';
        $codeError = isset($errors['setup_code']) ? '<p class="error" id="code-error" role="alert">'.$e($errors['setup_code']).'</p>' : '';
        $codeDescribedBy = isset($errors['setup_code']) ? 'code-help code-error" aria-invalid="true' : 'code-help';
        $databaseError = '';
        if (isset($errors['database'])) {
            $databaseError = '<div class="error" id="database-error" role="alert"><p>'.$e($errors['database']).'</p>';
            if ($state['detail'] !== null && $state['detail'] !== '') {
                $databaseError .= '<details><summary>Technical details</summary><pre>'.$e($state['detail']).'</pre></details>';
            }
            $databaseError .= '</div>';
        }

        $options = '';
        foreach (FirstRunSetup::DRIVERS as $key => $driver) {
            $available = extension_loaded($driver['extension']);
            $checked = $values['driver'] === $key ? ' checked' : '';
            $disabled = $available ? '' : ' disabled';
            $note = $available ? $driver['help'] : 'Not available: PHP lacks the '.$driver['extension'].' extension.';
            $options .= '<label class="choice'.($available ? '' : ' unavailable').'"><input type="radio" name="driver" value="'.$key.'"'.$checked.$disabled.'>'
                .'<span><strong>'.$e($driver['label']).'</strong> <span class="muted">('.$e($driver['minimum']).' or newer)</span><br>'
                .'<span class="muted">'.$e($note).'</span></span></label>';
        }

        $codeFile = $e($codeFile);
        $disabledForm = $blocked || $codeProblem !== null ? ' disabled' : '';
        $codeBlock = $codeProblem !== null ? '<p class="error">'.$e($codeProblem).'</p>' : '';
        $marker = FirstRunSetup::MARKER_HEADER;
        $sqliteSelected = $values['driver'] === 'sqlite' ? ' hidden' : '';
        $trustChecked = $values['trust_certificate'] !== '' ? ' checked' : '';
        $persistentChecked = $values['persistent_connections'] !== '' ? ' checked' : '';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Set up {$product}</title>
<style>
:root { color-scheme: light dark; --bg: #f6f7f9; --fg: #1f2328; --muted: #57606a; --card: #fff; --line: #d0d7de; --accent: #0b5cad; --accent-fg: #fff; --ok: #1a7f37; --warn: #9a6700; --fail: #cf222e; --field: #fff; }
@media (prefers-color-scheme: dark) { :root { --bg: #111418; --fg: #e6edf3; --muted: #9da7b3; --card: #1a1f26; --line: #30363d; --accent: #4493f8; --accent-fg: #0d1117; --ok: #3fb950; --warn: #d29922; --fail: #f85149; --field: #0d1117; } }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 42rem; margin: 0 auto; padding: 2rem 1rem 4rem; }
h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
h2 { font-size: 1.1rem; margin: 0 0 .5rem; }
p { margin: .5rem 0; }
.lead { color: var(--muted); margin-bottom: 1.5rem; }
section { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 1.25rem; margin: 0 0 1rem; }
.muted { color: var(--muted); }
.checks { list-style: none; padding: 0; margin: .75rem 0 0; }
.check { display: flex; gap: .75rem; padding: .5rem 0; border-top: 1px solid var(--line); }
.check:first-child { border-top: 0; }
.icon { flex: 0 0 1.5rem; height: 1.5rem; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: .85rem; color: #fff; }
.ok .icon { background: var(--ok); } .warn .icon { background: var(--warn); } .fail .icon { background: var(--fail); }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
summary { cursor: pointer; font-weight: 600; }
label { display: block; font-weight: 600; margin: .75rem 0 .25rem; }
input[type=text], input[type=password], input[type=number] { width: 100%; padding: .55rem .65rem; border: 1px solid var(--line); border-radius: 6px; background: var(--field); color: var(--fg); font: inherit; }
input:focus-visible, button:focus-visible, summary:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
.code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .08em; text-transform: uppercase; }
.choice { display: flex; gap: .6rem; font-weight: 400; border: 1px solid var(--line); border-radius: 8px; padding: .6rem .75rem; margin: .5rem 0; cursor: pointer; }
.choice input { margin-top: .3rem; }
.unavailable { opacity: .6; cursor: not-allowed; }
.row { display: grid; grid-template-columns: 1fr 8rem; gap: .75rem; }
fieldset { border: 0; padding: 0; margin: 0; }
fieldset[disabled] { opacity: .6; }
.help { color: var(--muted); font-size: .9rem; margin: .25rem 0 0; }
.check-label { display: flex; gap: .5rem; align-items: center; font-weight: 600; margin-top: 1rem; }
.error { color: var(--fail); font-weight: 600; }
.error pre { white-space: pre-wrap; font-weight: 400; color: var(--muted); }
.notice { border-left: 4px solid var(--warn); padding: .25rem .75rem; margin: .75rem 0 0; }
button { margin-top: 1.25rem; width: 100%; padding: .75rem; border: 0; border-radius: 8px; background: var(--accent); color: var(--accent-fg); font: inherit; font-weight: 700; cursor: pointer; }
button[disabled] { opacity: .5; cursor: not-allowed; }
code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .92em; }
@media (max-width: 30rem) { .row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<main>
<h1>Set up {$product}</h1>
<p class="lead">Two short steps: connect a database here, then create your administrator account.</p>
{$formError}
<section aria-labelledby="checks-title">
<details{$checksOpen}>
<summary id="checks-title">Server check: {$e($summary)}</summary>
<ul class="checks">{$rows}</ul>
<div id="probe" class="notice" hidden></div>
<noscript><p class="help">The web server routing checks need JavaScript. Everything else on this page works without it.</p></noscript>
</details>
</section>

<form method="post" action="" novalidate>
<input type="hidden" name="_token" value="{$e($csrf)}">
<fieldset{$disabledForm}>
<section aria-labelledby="code-title">
<h2 id="code-title">1. Confirm this is your server</h2>
<p id="code-help">Open <code>{$codeFile}</code> with your hosting panel's file manager (it is next to <code>README.md</code>) and copy the code from it. This stops anyone else from finishing setup before you do.</p>
{$codeBlock}
<label for="setup_code">Setup code</label>
<input type="text" id="setup_code" name="setup_code" class="code" value="{$e($values['setup_code'])}" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX" aria-describedby="{$codeDescribedBy}" required>
{$codeError}
</section>

<section aria-labelledby="db-title">
<h2 id="db-title">2. Connect a database</h2>
{$databaseError}
<div role="radiogroup" aria-labelledby="db-title">{$options}</div>
<div id="server-fields"{$sqliteSelected}>
<label for="host">Server</label>
<div class="row">
<input type="text" id="host" name="host" value="{$e($values['host'])}" autocomplete="off" spellcheck="false" aria-describedby="host-help">
<input type="text" id="port" name="port" value="{$e($values['port'])}" inputmode="numeric" autocomplete="off" placeholder="Port" aria-label="Port (optional)">
</div>
<p class="help" id="host-help">Usually <code>localhost</code>. Leave the port empty for the default.</p>
<label for="name">Database name</label>
<input type="text" id="name" name="name" value="{$e($values['name'])}" autocomplete="off" spellcheck="false">
<label for="user">User name</label>
<input type="text" id="user" name="user" value="{$e($values['user'])}" autocomplete="off" spellcheck="false">
<label for="password">Password</label>
<input type="password" id="password" name="password" value="{$e($values['password'])}" autocomplete="new-password">
<div id="trust-field">
<label class="check-label"><input type="checkbox" name="trust_certificate" value="1"{$trustChecked} aria-describedby="trust-help"> Trust the server certificate <span class="muted">(SQL Server only)</span></label>
<p class="help" id="trust-help">Needed when SQL Server uses a self-signed certificate, which is common on servers you run yourself. The connection is still encrypted.</p>
</div>
<div id="persistent-field">
<label class="check-label"><input type="checkbox" name="persistent_connections" value="1"{$persistentChecked} aria-describedby="persistent-help"> Keep database connections open between requests <span class="muted">(PostgreSQL only, recommended)</span></label>
<p class="help" id="persistent-help">Each PHP worker reuses its connection instead of opening a new one for every page view, which more than doubled how much traffic a server handled in testing. Each worker holds one connection, so turn this off if your database host allows only a few connections.</p>
</div>
</div>
<p class="help">Saving tests the connection, detects the server version and stores the settings, with a newly generated application secret, in <code>.env.local</code>.</p>
<button type="submit">Save and continue</button>
</section>
</fieldset>
</form>
<p class="help">Next: create the administrator account. The <a href="{$installGuide}#release-zip-on-a-web-host" target="_blank" rel="noopener noreferrer">installation guide</a> (also <code>DEPLOYMENT.md</code> in the application folder) describes this setup and links step-by-step guides for hosting panels.</p>
</main>
<script nonce="{$nonce}">
(function () {
  var fields = document.getElementById('server-fields');
  var trust = document.getElementById('trust-field');
  var persistent = document.getElementById('persistent-field');
  function sync() {
    var chosen = document.querySelector('input[name=driver]:checked');
    fields.hidden = !!chosen && chosen.value === 'sqlite';
    trust.hidden = !chosen || chosen.value !== 'sqlsrv';
    persistent.hidden = !chosen || chosen.value !== 'pgsql';
  }
  document.querySelectorAll('input[name=driver]').forEach(function (input) { input.addEventListener('change', sync); });
  sync();

  if (!window.fetch) { return; }
  var base = '{$base}';
  function reaches(path) {
    return fetch(base + path, { method: 'HEAD', cache: 'no-store', credentials: 'same-origin' })
      .then(function (response) { return response.headers.get('{$marker}') === '1'; })
      .catch(function () { return null; });
  }
  Promise.all([reaches('/install'), reaches('/aggregate.js'), reaches('/branding/theme.css')]).then(function (results) {
    var messages = [];
    if (results[0] === false) {
      messages.push('Addresses other than the home page do not reach the application, so the next step would show "not found". The web server must pass them to public/index.php: with Apache, allow public/.htaccess to take effect; with nginx, add the routing rules from the Web Server Configuration section of DEPLOYMENT.md ({$installGuide}#web-server-configuration). Your hosting panel\'s guide has the exact steps.');
    }
    if (results[1] === false || results[2] === false) {
      messages.push('The web server answers some .js and .css addresses itself, so tracker settings and dashboard colours saved later would not take effect. /aggregate.js and /branding/theme.css must reach PHP: if your hosting panel lets nginx serve static files directly, remove js and css from that list or turn the option off.');
    }
    if (messages.length === 0) { return; }
    var box = document.getElementById('probe');
    box.hidden = false;
    box.innerHTML = '<p><strong>Web server settings to change</strong></p>';
    messages.forEach(function (text) { var p = document.createElement('p'); p.textContent = text; box.appendChild(p); });
    box.closest('details').open = true;
  });
})();
</script>
</body>
</html>
HTML;
    }
}
