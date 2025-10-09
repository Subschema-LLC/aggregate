<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        // Validate critical security configuration
        $configLoader = $this->container->get(\App\Service\AggregateConfigLoader::class);
        $salt = $configLoader->getWithEnvFallback('daily_salt_secret', '');

        if (empty($salt) || $salt === 'dev-salt' || strlen($salt) < 16) {
            throw new \RuntimeException(
                'daily_salt_secret must be set to a secure random string (minimum 16 characters) in config/aggregate.yaml. ' .
                'Generate one with: openssl rand -base64 32'
            );
        }
    }
}
