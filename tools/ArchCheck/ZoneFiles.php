<?php

declare(strict_types=1);

namespace App\Tools\ArchCheck;

/**
 * Rules for every file in the zones, not only PHP: PascalCase folders and
 * files (rule 17), and for Twig views the parts of rules 13 and 14 a machine
 * can see. A view names no entity, a feature's view renders nothing outside
 * its own folder, and no view holds inline CSS or JS. Whether a view only
 * displays values its feature shaped is left to review.
 */
final class ZoneFiles
{
    private const INLINE = [
        '/<style\b/i' => 'a <style> element',
        '/<script\b/i' => 'a <script> element',
        '/\sstyle\s*=/i' => 'a style attribute',
        '/\son[a-z]+\s*=/i' => 'an inline event handler',
    ];
    /** Twig tags and functions that render or load another template. */
    private const TEMPLATE_REFERENCE =
        '/\b(include|embed|extends|import|from|use|source)\b\s*\(?\s*([\'"])([^\'"]+)\2/';

    /** @return list<string> */
    public function check(string $path, string $contents): array
    {
        $zone = ZoneRules::zoneOf($path);
        if ($zone === null) {
            return [];
        }
        $problems = $this->casing($path);
        if (str_ends_with($path, '.twig')) {
            $problems = [...$problems, ...$this->view($path, $zone, $contents)];
        }

        return $problems;
    }

    /** @return list<string> */
    private function casing(string $path): array
    {
        $parts = array_slice(explode('/', $path), 2);
        $problems = [];
        foreach ($parts as $i => $part) {
            $name = $i === count($parts) - 1 ? explode('.', $part, 2)[0] : $part;
            if (preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) !== 1) {
                $problems[] = sprintf('%s: "%s" is not PascalCase (rule 17)', $path, $name);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function view(string $path, string $zone, string $contents): array
    {
        $problems = [];
        foreach (explode("\n", $contents) as $index => $line) {
            $where = sprintf('%s:%d', $path, $index + 1);
            foreach (self::INLINE as $pattern => $what) {
                if (preg_match($pattern, $line) === 1) {
                    $problems[] = sprintf('%s: %s; put CSS and JS in their own files', $where, $what);
                }
            }
            if (preg_match('/App\\\\{1,2}Entities\b/', $line) === 1) {
                $problems[] = $where.': names an entity; the feature shapes the values a view prints';
            }
            if ($zone !== 'Features') {
                continue;
            }
            $own = '@Features/'.explode('/', $path)[2].'/';
            preg_match_all(self::TEMPLATE_REFERENCE, $line, $matches);
            foreach ($matches[3] as $template) {
                if (!str_starts_with($template, $own)) {
                    $problems[] = sprintf(
                        '%s: renders %s; a feature view renders only its own templates, '
                        .'and the page places the others',
                        $where,
                        $template,
                    );
                }
            }
        }

        return $problems;
    }
}
