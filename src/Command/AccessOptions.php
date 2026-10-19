<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\Access\DashboardPages;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared by the user and permission group commands: reading --page options
 * and printing access the way the profile page shows it.
 */
trait AccessOptions
{
    /**
     * Parses --page=PAGE:LEVEL options into changes; LEVEL "none" removes the page.
     *
     * @param list<mixed> $options
     *
     * @return array<string, ?string> page => level, or null to remove it
     */
    private static function pageOptions(array $options): array
    {
        $pages = [];
        foreach ($options as $option) {
            if (!is_string($option) || preg_match('/^([a-z_]+):(view|edit|none)$/D', $option, $match) !== 1) {
                throw new \InvalidArgumentException(sprintf('Write --page as PAGE:view, PAGE:edit or PAGE:none, such as --page=tag_manager:edit. Pages: %s.', implode(', ', array_keys(DashboardPages::PAGES))));
            }
            if (!DashboardPages::hasPage($match[1])) {
                throw new \InvalidArgumentException(sprintf('Unknown page "%s". Pages: %s.', $match[1], implode(', ', array_keys(DashboardPages::PAGES))));
            }
            $pages[$match[1]] = $match[2] === 'none' ? null : $match[2];
        }

        return $pages;
    }

    /**
     * @param array<string, string>  $pages
     * @param array<string, ?string> $changes
     *
     * @return array<string, string>
     */
    private static function applyPages(array $pages, array $changes): array
    {
        foreach ($changes as $page => $level) {
            if ($level === null) {
                unset($pages[$page]);
            } else {
                $pages[$page] = $level;
            }
        }

        return $pages;
    }

    /** @param array<string, mixed> $summary EffectiveAccess::summary() */
    private static function printSummary(SymfonyStyle $io, array $summary): void
    {
        if ($summary['admin']) {
            $io->text('Administrator: every page and website, and managing users and permission groups.');

            return;
        }
        if ($summary['every_website'] !== []) {
            $io->text('Websites: every website and group (from '.implode(', ', $summary['every_website']).')');
        } else {
            $lines = [];
            foreach ($summary['groups'] as $group => $sources) {
                $lines[] = sprintf('website group %s (from %s)', $group, implode(', ', $sources));
            }
            foreach ($summary['sites'] as $token => $sources) {
                $lines[] = sprintf('website %s (from %s)', $token, implode(', ', $sources));
            }
            $io->text($lines === [] ? 'Websites: none' : ['Websites:', ...array_map(static fn (string $line): string => '  - '.$line, $lines)]);
        }
        if ($summary['pages'] === []) {
            $io->text('Pages: none');
        } else {
            $io->table(['Page', 'Level', 'From'], array_map(static fn (array $page): array => [
                $page['label'].' ('.$page['page'].')',
                $page['level'],
                implode(', ', array_map(static fn (array $source): string => $source['label'], $page['sources'])),
            ], $summary['pages']));
        }
        if ($summary['missing_permission_groups'] !== []) {
            $io->warning('These assigned permission groups no longer exist and grant nothing: '.implode(', ', $summary['missing_permission_groups']));
        }
    }
}
