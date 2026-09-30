<?php

/*
 * First-run gate, loaded by public/index.php before Composer or the kernel when
 * no .env.local exists. Until the installation has an application secret and a
 * database, every web request gets the browser setup page instead of a crash.
 *
 * The page (App\Setup\FirstRunSetup) checks the server, asks for the one-time
 * code in SETUP-CODE.txt, tests the database connection and writes .env.local.
 * The existing /install page then creates the first administrator.
 *
 * This file must stay parseable on old PHP versions, so that a site running an
 * unsupported PHP gets a readable message instead of a parse error. The setup
 * page itself requires the supported version.
 */

return static function ($projectDir) {
    // Real environment variables win over .env files: a deployment that sets
    // them has been configured by its administrator.
    foreach (array('APP_SECRET', 'DATABASE_URL') as $name) {
        $value = isset($_SERVER[$name]) ? $_SERVER[$name] : (isset($_ENV[$name]) ? $_ENV[$name] : getenv($name));
        if (is_string($value) && trim($value) !== '') {
            return false;
        }
    }

    // Any environment file that defines a secret also means the installation
    // is configured (by hand, by install.sh, or by this page).
    foreach (array('.env.local', '.env.local.php', '.env.prod.local', '.env.prod', '.env') as $file) {
        $path = $projectDir.'/'.$file;
        if (!is_file($path)) {
            continue;
        }
        if ($file === '.env.local' || $file === '.env.local.php') {
            return false;
        }
        $contents = @file_get_contents($path, false, null, 0, 262144);
        if (!is_string($contents)) {
            // Unreadable configuration is a server problem, not a fresh install.
            return false;
        }
        if (preg_match_all('/^[ \t]*(?:export[ \t]+)?APP_SECRET[ \t]*=[ \t]*(.*)$/m', $contents, $matches) > 0) {
            $value = trim((string) end($matches[1]));
            $value = trim(preg_replace('/(^|[ \t])#.*$/', '', $value), " \t\"'");
            if ($value !== '') {
                return false;
            }
        }
    }

    if (PHP_VERSION_ID < 80200) {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex');
        }
        $version = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>Setup: newer PHP needed</title></head>'
            .'<body style="font:16px/1.5 system-ui,sans-serif;max-width:36rem;margin:3rem auto;padding:0 1rem">'
            .'<h1 style="font-size:1.4rem">This site needs PHP 8.2 or newer</h1>'
            .'<p>It is running PHP '.$version.'. Choose PHP 8.2 or newer for this site in your hosting panel\'s '
            .'PHP settings, or ask your hosting provider, then reload this page.</p></body></html>';

        return true;
    }

    // Composer is not loaded yet; the two classes have no other dependencies.
    foreach (array('SetupCode', 'FirstRunSetup') as $class) {
        if (!class_exists('App\\Setup\\'.$class, false)) {
            require_once dirname(__DIR__).'/src/Setup/'.$class.'.php';
        }
    }

    $setup = new \App\Setup\FirstRunSetup($projectDir, $_SERVER, $_POST, $_COOKIE);

    return $setup->handle();
};
