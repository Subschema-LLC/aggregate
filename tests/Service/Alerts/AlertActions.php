<?php

declare(strict_types=1);

namespace App\Tests\Service\Alerts;

use App\Features\Alerts\DeleteAlertRule\DeleteAlertRule;
use App\Features\Destinations\DeleteDestination\DeleteDestination;
use App\Features\Alerts\SaveAlertRule\SaveAlertRule;
use App\Features\Destinations\SaveDestination\SaveDestination;
use App\Features\Destinations\SaveSigningSecret\SaveSigningSecret;
use App\Integrations\Alerts\AlertsYamlStore;
use App\Integrations\Alerts\FileSigningSecrets;
use App\Integrations\Alerts\YamlAlertRules;
use App\Integrations\Alerts\YamlDestinations;
use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSecrets;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;

/** The alert action features wired as config/wiring.yaml wires them, over one project directory. */
final class AlertActions
{
    public readonly SaveAlertRule $saveRule;
    public readonly DeleteAlertRule $deleteRule;
    public readonly SaveDestination $saveDestination;
    public readonly DeleteDestination $deleteDestination;
    public readonly SaveSigningSecret $saveSecret;

    public function __construct(string $projectDir)
    {
        $config = new AggregateConfigLoader($projectDir, 'test');
        $validator = new AlertRuleValidator(new WebsiteConfigManager($projectDir), new WebsiteScopeCatalog($projectDir, 'test'));
        $store = new AlertsYamlStore($config, $validator->mapping(), $validator->references());
        $rules = new YamlAlertRules($store);
        $destinations = new YamlDestinations($store);
        $secrets = new FileSigningSecrets(new AlertSecrets($projectDir));

        $this->saveRule = new SaveAlertRule($rules);
        $this->deleteRule = new DeleteAlertRule($rules);
        $this->saveDestination = new SaveDestination($destinations, $secrets);
        $this->deleteDestination = new DeleteDestination($destinations, $secrets);
        $this->saveSecret = new SaveSigningSecret($secrets);
    }
}
