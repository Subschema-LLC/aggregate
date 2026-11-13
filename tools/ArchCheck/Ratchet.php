<?php

declare(strict_types=1);

namespace App\Tools\ArchCheck;

/**
 * Holds the code that has not moved to a zone yet: it may shed FIDES findings,
 * never gain them, and a file over 200 lines may not grow. Each finding is
 * listed by name in the baseline, so fixing one cannot pay for another.
 *
 *   app-inheritance  a class extends another App\ class (rule 8)
 *   abstract-class   an abstract class exists to be extended (rule 8)
 *   not-final        a concrete class is open to extension (rule 8);
 *                    Doctrine entities are exempt, since their proxies extend them
 *   file-size        a file is over 200 lines (rule 15); its length is a ceiling
 */
final class Ratchet
{
    public const RULES = ['app-inheritance', 'abstract-class', 'not-final', 'file-size'];
    private const MAX_LINES = 200;
    /** Data tables rather than concepts; their length is their content. */
    private const SIZE_EXEMPT = ['src/Service/BotDetection/BotUserAgentPatterns.php'];

    /** @var array<string, array<string, int|string>> rule => [subject => detail] */
    private array $found = ['app-inheritance' => [], 'abstract-class' => [], 'not-final' => [], 'file-size' => []];

    public function add(SourceFile $file): void
    {
        if (ZoneRules::zoneOf($file->path) !== null) {
            return;
        }
        if ($file->lineCount > self::MAX_LINES && !in_array($file->path, self::SIZE_EXEMPT, true)) {
            $this->found['file-size'][$file->path] = $file->lineCount;
        }
        foreach ($file->classes as $class) {
            $name = $class['name'];
            if ($class['extends'] !== null && str_starts_with($class['extends'], 'App\\')) {
                $this->found['app-inheritance'][$name] = 'extends '.$class['extends'];
            }
            if ($class['abstract']) {
                $this->found['abstract-class'][$name] = 'abstract';
            } elseif ($class['kind'] === 'class' && !$class['final'] && !$class['entity']) {
                $this->found['not-final'][$name] = 'not final';
            }
        }
    }

    /** @return array<string, array<string, int|string>> */
    public function findings(): array
    {
        $found = $this->found;
        foreach ($found as &$subjects) {
            ksort($subjects);
        }

        return $found;
    }

    /**
     * @param array<string, array<string, int|string>> $baseline
     *
     * @return list<string>
     */
    public function compare(array $baseline): array
    {
        $problems = [];
        foreach ($this->findings() as $rule => $subjects) {
            $allowed = $baseline[$rule] ?? [];
            foreach ($subjects as $subject => $detail) {
                if (!array_key_exists($subject, $allowed)) {
                    $problems[] = sprintf('%s: %s (%s) is new; compose or split it instead', $rule, $subject, $detail);
                } elseif ($rule === 'file-size' && $detail > $allowed[$subject]) {
                    $problems[] = sprintf('file-size: %s grew from %d to %d lines; move the new concept into its own file', $subject, $allowed[$subject], $detail);
                }
            }
            foreach (array_keys(array_diff_key($allowed, $subjects)) as $subject) {
                $problems[] = sprintf('%s: %s is fixed; run with --update-baseline so it stays fixed', $rule, $subject);
            }
        }

        return $problems;
    }

    /**
     * The baseline with fixed findings removed and shrunk files lowered. It
     * never admits a finding the old baseline did not hold.
     *
     * @param array<string, array<string, int|string>>|null $baseline null to start one
     *
     * @return array<string, array<string, int|string>>
     */
    public function tightened(?array $baseline): array
    {
        $found = $this->findings();
        if ($baseline === null) {
            return $found;
        }
        $tightened = [];
        foreach ($found as $rule => $subjects) {
            $kept = array_intersect_key($subjects, $baseline[$rule] ?? []);
            if ($rule === 'file-size') {
                foreach ($kept as $subject => $lines) {
                    $kept[$subject] = min($lines, $baseline[$rule][$subject]);
                }
            }
            $tightened[$rule] = $kept;
        }

        return $tightened;
    }
}
