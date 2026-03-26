<?php

namespace App;

use App\Service\AggregateConfigLoader;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Bootstrap env vars from aggregate.yaml before the container is used.
        // This runs for both HTTP requests AND console commands (cache:clear, migrations, etc.)
        // We instantiate AggregateConfigLoader directly to avoid a container dependency.
        $this->bootstrapConfigEnvVars();

        parent::boot();

        // Validate daily_salt_secret only when one has been configured.
        // During initial install the salt does not exist yet — the installer
        // generates and persists it before creating the admin user.
        $configLoader = $this->container->get(AggregateConfigLoader::class);
        $salt = $configLoader->getWithEnvFallback('daily_salt_secret', '');

        if (!empty($salt) && $salt !== 'dev-salt' && strlen($salt) < 16) {
            throw new \RuntimeException(
                'daily_salt_secret in config/aggregate.yaml is too short (minimum 16 characters). ' .
                'Regenerate with: openssl rand -base64 32'
            );
        }
    }

    private function bootstrapConfigEnvVars(): void
    {
        $configLoader = new AggregateConfigLoader($this->getProjectDir(), $this->getEnvironment());

        $map = [
            'DATABASE_URL'           => 'database_url',
            'MESSENGER_TRANSPORT_DSN' => 'messenger_transport_dsn',
        ];

        foreach ($map as $envKey => $configKey) {
            if (isset($_ENV[$envKey]) || isset($_SERVER[$envKey])) {
                continue; // already set — don't override
            }
            $value = $configLoader->getWithEnvFallback($configKey);
            if ($value !== null) {
                $_ENV[$envKey] = $_SERVER[$envKey] = (string) $value;
                putenv("$envKey=$value");
            }
        }
    }
}
