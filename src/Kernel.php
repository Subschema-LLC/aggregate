<?php

namespace App;

use App\DependencyInjection\RemoveControllersPass;
use App\Service\Cluster\ServerRole;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Yaml\Yaml;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private ?ServerRole $serverRole = null;

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // The role this container is built for: app:server:role compares it
        // with the configured one, since changing the role needs cache:clear.
        $container->setParameter('aggregate.server_role.compiled', $this->serverRoleForBoot()->role);

        // Controllers behind the route files this role does not load. The
        // service files are not loaded yet here, so a pass removes them.
        $controllers = [];
        foreach ($this->serverRoleForBoot()->skippedRouteFiles() as $file) {
            array_push($controllers, ...ServerRole::controllersInRouteFile($this->getProjectDir().'/config/'.$file));
        }
        if ($controllers !== []) {
            $container->addCompilerPass(new RemoveControllersPass($controllers), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);
        }

        if ($this->isDashboardFeatureEnabledForBoot()) {
            return;
        }

        foreach ([
            'App\\Controller\\WebsitesController',
            'App\\Controller\\ApplicationSettingsController',
            'App\\Controller\\BrandingSettingsController',
            'App\\Controller\\CollectionSettingsController',
            'App\\Controller\\PrivacySettingsController',
            'App\\Controller\\UsersController',
            'App\\Controller\\SetupController',
            'App\\Controller\\TagManagerController',
            'App\\Controller\\BrowserAssetsController',
            'App\\Controller\\DataLifecycleController',
            'App\\Controller\\DataTransferController',
            'App\\Controller\\DataModelController',
            'App\\Controller\\BiGlossaryController',
            'App\\Controller\\BiPageGroupsController',
            'App\\Controller\\ReportingTimezoneController',
            'App\\Controller\\EventExamplesController',
            'App\\Controller\\FeatureFlagsController',
            'App\\Controller\\InternalTrafficController',
            'App\\Controller\\BigQueryController',
            'App\\Controller\\ReportingDatabaseController',
            'App\\Controller\\ViewSyncController',
            'App\\Controller\\ApiTokensController',
            'App\\Controller\\ScheduledTasksController',
            'App\\Controller\\AlertsController',
            'App\\Controller\\AuditTrailController',
            'App\\Controller\\UpdatesController',
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
        foreach ($this->serverRoleForBoot()->routeFiles() as $file) {
            $routes->import($configDir.'/'.$file);
        }

        if ($this->isDashboardFeatureEnabledForBoot()) {
            $routes->import($configDir.'/routes_dashboard.yaml');
        }

        if ($fileName = (new \ReflectionObject($this))->getFileName()) {
            $routes->import($fileName, 'attribute');
        }
    }

    /** The server role, read once per kernel the way the dashboard switch is. */
    private function serverRoleForBoot(): ServerRole
    {
        return $this->serverRole ??= ServerRole::resolve($this->getProjectDir(), $this->environment);
    }

    private function isDashboardFeatureEnabledForBoot(): bool
    {
        // Only the roles that administer have a dashboard.
        if (!$this->serverRoleForBoot()->administers()) {
            return false;
        }

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
            $config = $this->parseLockedYamlFile($configPath);
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

    private function parseLockedYamlFile(string $path): mixed
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The application configuration could not be opened.');
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('The application configuration could not be locked for reading.');
            }

            $yaml = stream_get_contents($handle);
            if (!is_string($yaml)) {
                throw new \RuntimeException('The application configuration could not be read.');
            }

            return $yaml !== '' ? Yaml::parse($yaml) : null;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
