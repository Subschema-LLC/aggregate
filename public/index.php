<?php

use App\Kernel;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Early application configuration loader from config/aggregate.yaml (optional)
// This allows on‑prem users to set a single YAML file instead of multiple env vars.
$aggregateConfigPath = dirname(__DIR__) . '/config/aggregate.yaml';
if (is_file($aggregateConfigPath)) {
    try {
        $cfg = Yaml::parseFile($aggregateConfigPath) ?? [];
        if (!is_array($cfg)) { $cfg = []; }
        $map = [
            'database_url' => 'DATABASE_URL',
            'messenger_transport_dsn' => 'MESSENGER_TRANSPORT_DSN',
            'daily_salt_secret' => 'DAILY_SALT_SECRET',
            'rate_limit_per_minute' => 'RATE_LIMIT_PER_MINUTE',
            'app_host' => 'APP_HOST',
        ];
        foreach ($map as $key => $envName) {
            if (array_key_exists($key, $cfg) && $cfg[$key] !== null && $cfg[$key] !== '') {
                $value = (string) $cfg[$key];
                // only set if not already provided via real env var to allow overrides
                if (getenv($envName) === false) {
                    putenv($envName.'='.$value);
                    $_ENV[$envName] = $value;
                    $_SERVER[$envName] = $value;
                }
            }
        }
    } catch (\Throwable $e) {
        // If YAML parsing fails, continue with normal boot. Errors will appear in logs later if needed.
    }
}

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
