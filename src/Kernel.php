<?php

namespace App;

use App\Service\AggregateConfigLoader;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Yaml\Yaml;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if ($this->isDashboardFeatureEnabledForBoot()) {
            return;
        }

        foreach ([
            'App\\Controller\\DashboardController',
            'App\\Controller\\InstallController',
            'App\\Controller\\SecurityController',
        ] as $serviceId) {
            if ($container->hasDefinition($serviceId)) {
                $container->removeDefinition($serviceId);
            }
        }
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $configDir = preg_replace('{/config$}', '/{config}', $this->getConfigDir());

        $container->import($configDir.'/{packages}/*.{php,yaml}');
        $container->import($configDir.'/{packages}/'.$this->environment.'/*.{php,yaml}');
        $container->import($configDir.'/services.yaml');
        $container->import($configDir.'/{services}_'.$this->environment.'.yaml');

        if ($this->isDashboardFeatureEnabledForBoot()) {
            $container->import($configDir.'/services_dashboard.yaml');
            $container->import($configDir.'/{services_dashboard}_'.$this->environment.'.yaml');
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $configDir = preg_replace('{/config$}', '/{config}', $this->getConfigDir());

        $routes->import($configDir.'/{routes}/'.$this->environment.'/*.{php,yaml}');
        $routes->import($configDir.'/{routes}/*.{php,yaml}');
        $routes->import($configDir.'/routes_public.yaml');

        if ($this->isDashboardFeatureEnabledForBoot()) {
            $routes->import($configDir.'/routes_dashboard.yaml');
        }

        if ($fileName = (new \ReflectionObject($this))->getFileName()) {
            $routes->import($fileName, 'attribute');
        }
    }

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

    private function isDashboardFeatureEnabledForBoot(): bool
    {
        $envValue = $_ENV['DASHBOARD_ENABLED'] ?? $_SERVER['DASHBOARD_ENABLED'] ?? null;
        if (null !== $envValue && '' !== (string) $envValue) {
            $parsed = filter_var($envValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            return null === $parsed ? true : $parsed;
        }

        $configPath = $this->getProjectDir().'/config/aggregate.yaml';
        if (!is_file($configPath)) {
            return true;
        }

        try {
            $config = Yaml::parseFile($configPath);
        } catch (\Throwable) {
            return true;
        }

        if (!is_array($config)) {
            return true;
        }

        $environmentConfig = $config['environments'][$this->environment] ?? null;
        if (!is_array($environmentConfig) || !array_key_exists('dashboard_enabled', $environmentConfig)) {
            return true;
        }

        $parsed = filter_var($environmentConfig['dashboard_enabled'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if (null !== $parsed) {
            return $parsed;
        }

        return (bool) $environmentConfig['dashboard_enabled'];
    }
}
