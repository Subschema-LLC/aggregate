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
}
