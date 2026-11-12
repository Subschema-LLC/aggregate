<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\Glossary\BiGlossarySettings;
use App\Service\Glossary\GlossarySync;
use App\Service\Settings\AbstractSection;

/**
 * The BI glossary, as the BI glossary page saves it: the whole bi_glossary
 * mapping is checked, saved and published to the database.
 */
final class BiGlossarySection extends AbstractSection
{
    public const KEY = 'bi_glossary';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly BiGlossarySettings $settings,
        private readonly GlossarySync $sync,
    ) {
    }

    public function name(): string
    {
        return 'bi-glossary';
    }

    public function page(): string
    {
        return 'Reporting > BI glossary';
    }

    public function keys(): array
    {
        return [self::KEY];
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return [self::KEY => $this->settings->get()];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        if ($check) {
            $this->settings->validate($changes[self::KEY]);

            return [self::done(true, '')];
        }
        $this->settings->save($changes[self::KEY]);
        try {
            $this->sync->sync();
        } catch (\Throwable $e) {
            return ['Configuration saved, but the database was not updated: '.$e->getMessage().' Run php bin/console app:analytics:glossary:sync after correcting the problem.'];
        }

        return ['BI glossary saved and synced.'];
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
