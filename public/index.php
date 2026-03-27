<?php

use App\Kernel;

// Allow deployments that provide only .env.local or real OS env vars.
$projectDir = dirname(__DIR__);
if (!is_file($projectDir.'/.env')) {
    $_SERVER['APP_RUNTIME_OPTIONS'] ??= [];

    if (is_file($projectDir.'/.env.local')) {
        $_SERVER['APP_RUNTIME_OPTIONS']['dotenv_path'] = $projectDir.'/.env.local';
    } else {
        $_SERVER['APP_RUNTIME_OPTIONS']['disable_dotenv'] = true;
        $_SERVER['APP_ENV'] ??= $_ENV['APP_ENV'] ?? 'prod';
        $_SERVER['APP_DEBUG'] ??= $_ENV['APP_DEBUG'] ?? ('prod' === $_SERVER['APP_ENV'] ? '0' : '1');
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
