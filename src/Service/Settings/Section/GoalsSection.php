<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\GoalDefinitions;
use App\Service\Settings\AbstractSection;

/**
 * The conversion goals, as the Goals page saves them: the whole mapping of
 * goal codes to their label, enabled and anonymous options, checked
 * together and written to config/goals.local.yaml.
 *
 *     php bin/console app:config set goals.goals '{purchase: {label: Purchase, enabled: true, anonymous: true}}'
 *     php bin/console app:config export goals > goals.yaml
 */
final class GoalsSection extends AbstractSection
{
    public const KEY = 'goals';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly GoalDefinitions $goals,
    ) {
    }

    public function name(): string
    {
        return 'goals';
    }

    public function page(): string
    {
        return 'Collection > Goals';
    }

    public function keys(): array
    {
        return [self::KEY];
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return [self::KEY => $this->goals->all()];
    }

    public function environmentOverrides(?string $target = null): array
    {
        $this->assertTarget($target);

        return [];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $goals = GoalDefinitions::validate($changes[self::KEY]);
        if ($check) {
            return [self::done(true, '')];
        }
        $this->goals->save($goals);

        return [sprintf(
            'Saved %d %s to %s. Collection uses them from the next request; run php bin/console app:analytics:glossary:sync to publish changed labels to BI tools.',
            count($goals),
            count($goals) === 1 ? 'goal' : 'goals',
            GoalDefinitions::LOCAL_FILE,
        )];
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
