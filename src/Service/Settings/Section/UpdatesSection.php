<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\ApplicationUpdateService;
use App\Service\Settings\AbstractSection;
use App\Service\UpdateSettings;

/**
 * The branch updates come from, as the Updates page's settings save it. How
 * the installation updates is chosen with app:updates:method, as on the
 * page's own form.
 */
final class UpdatesSection extends AbstractSection
{
    public const BRANCH = 'updates_branch';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly UpdateSettings $settings,
        private readonly ApplicationUpdateService $updates,
    ) {
    }

    public function name(): string
    {
        return 'updates';
    }

    public function page(): string
    {
        return 'Administration > Updates > Settings';
    }

    public function keys(): array
    {
        return [self::BRANCH];
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return [self::BRANCH => $this->settings->branch()];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $branch = UpdateSettings::validateBranch(is_string($changes[self::BRANCH]) ? trim($changes[self::BRANCH]) : $changes[self::BRANCH]);
        if (!$check) {
            $this->settings->saveBranch($branch);
            $this->updates->check(true);
        }

        return [self::done($check, 'Update settings saved to the YAML configuration.')];
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
