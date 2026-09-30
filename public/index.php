<?php

use App\Kernel;

// Allow deployments that provide only .env.local or real OS env vars.
$projectDir = dirname(__DIR__);

// Answer 503 while an update replaces application files (see config/maintenance.php).
if (is_file($projectDir.'/var/maintenance.json') && is_file($projectDir.'/config/maintenance.php')
    && (require $projectDir.'/config/maintenance.php')($projectDir)) {
    return;
}

// First run: until a secret and a database are configured, serve the browser
// setup page instead of the application (see config/setup.php). Kept to plain
// checks here so configured installations pay almost nothing for it.
if (!is_file($projectDir.'/.env.local') && !is_file($projectDir.'/.env.local.php')
    && is_file($projectDir.'/config/setup.php')) {
    $setup = require $projectDir.'/config/setup.php';
    if ($setup($projectDir)) {
        return;
    }
}

if (!is_file($projectDir.'/.env')) {
    $_SERVER['APP_RUNTIME_OPTIONS'] = isset($_SERVER['APP_RUNTIME_OPTIONS']) ? $_SERVER['APP_RUNTIME_OPTIONS'] : [];

    if (is_file($projectDir.'/.env.local')) {
        $_SERVER['APP_RUNTIME_OPTIONS']['dotenv_path'] = $projectDir.'/.env.local';
    } else {
        $_SERVER['APP_RUNTIME_OPTIONS']['disable_dotenv'] = true;
        $_SERVER['APP_ENV'] = isset($_SERVER['APP_ENV']) ? $_SERVER['APP_ENV'] : (isset($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : 'prod');
        $_SERVER['APP_DEBUG'] = isset($_SERVER['APP_DEBUG']) ? $_SERVER['APP_DEBUG'] : (isset($_ENV['APP_DEBUG']) ? $_ENV['APP_DEBUG'] : ('prod' === $_SERVER['APP_ENV'] ? '0' : '1'));
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
