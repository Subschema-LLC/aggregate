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
 *   file-size        a file is over 200 lines (rule 16); its length is a ceiling
 *   outside-zone     a file sits outside the five zones; new files go in a zone
 *   app-trait        an App\ trait, or a class that mixes one in (rule 8: code
 *                    mixed into a class counts as inheritance)
 *   names-integration  older code names an App\Integrations class; only
 *                    config/wiring.yaml should, so swapping one is one line
 *   unbound-contract  a contract in src/Entities or src/Shared that
 *                    config/wiring.yaml does not bind
 */
final class Ratchet
{
    public const RULES = [
        'app-inheritance', 'abstract-class', 'not-final', 'file-size', 'outside-zone', 'app-trait',
        'names-integration', 'unbound-contract',
    ];
    private const MAX_LINES = 200;
    /** Files Symfony expects at a fixed place outside the zones. */
    private const LOCATION_EXEMPT = ['src/Kernel.php'];

    /** @var array<string, array<string, int|string>> rule => [subject => detail] */
    private array $found = [
        'app-inheritance' => [], 'abstract-class' => [], 'not-final' => [], 'file-size' => [], 'outside-zone' => [],
        'app-trait' => [], 'names-integration' => [], 'unbound-contract' => [],
    ];

    /** @param string $wiring the contents of config/wiring.yaml */
    public function __construct(private readonly string $wiring = '')
    {
    }

    public function add(SourceFile $file): void
    {
        $zone = ZoneRules::zoneOf($file->path);
        if ($zone === 'Entities' || $zone === 'Shared') {
            foreach ($file->classes as $class) {
                $bound = preg_match('/^\s+'.preg_quote($class['name'], '/').'\s*:/m', $this->wiring) === 1;
                if ($class['kind'] === 'interface' && !$bound) {
                    $this->found['unbound-contract'][$class['name']] = 'not bound in config/wiring.yaml';
                }
            }
        }
        if ($zone !== null) {
            return;
        }
        if (!in_array($file->path, self::LOCATION_EXEMPT, true)) {
            $this->found['outside-zone'][$file->path] = 'not in a zone';
        }
        if ($file->lineCount > self::MAX_LINES && !isset(ZoneRules::SIZE_EXEMPT[$file->path])) {
            $this->found['file-size'][$file->path] = $file->lineCount;
        }
        $integrations = [];
        foreach ($file->references as $reference) {
            if (str_starts_with($reference['name'], 'App\\Integrations\\')) {
                $integrations[$reference['name']] = true;
            }
        }
        if ($integrations !== []) {
            ksort($integrations);
            $this->found['names-integration'][$file->path] = 'names '.implode(', ', array_keys($integrations));
        }
        $traits = [];
        foreach ($file->traitUses as $use) {
            if (str_starts_with($use['trait'], 'App\\')) {
                $traits[$use['class']][] = $use['trait'];
            }
        }
        foreach ($traits as $class => $used) {
            $this->found['app-trait'][$class] = 'uses '.implode(', ', $used);
        }
        foreach ($file->classes as $class) {
            $name = $class['name'];
            if ($class['kind'] === 'trait') {
                $this->found['app-trait'][$name] = 'is a trait';
            }
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
                if (!array_key_exists($subject, $allowed) && $rule === 'outside-zone') {
                    $problems[] = sprintf(
                        'outside-zone: %s is a new file outside the zones; put it in src/Entities, src/Features, '
                        .'src/Integrations, src/Delivery or src/Shared',
                        $subject,
                    );
                } elseif (!array_key_exists($subject, $allowed)) {
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
