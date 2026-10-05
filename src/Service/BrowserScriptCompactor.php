<?php

declare(strict_types=1);

namespace App\Service;

use Peast\Formatter\Compact;
use Peast\Peast;
use Peast\Renderer;

/**
 * Compacts browser scripts when no Terser build (app:assets:build-js) matches
 * the current source, as on a server updated from Git without Node. The
 * program is parsed and printed again without comments and formatting; names
 * are unchanged and every block keeps its braces. A leading license comment
 * is kept. Results are remembered by the source's hash.
 */
final class BrowserScriptCompactor
{
    /** Changing the output format changes this, so older results are not reused. */
    private const VERSION = '1';

    /** @var array<string, string> */
    private static array $memory = [];

    public function __construct(private readonly ?string $cacheDirectory = null)
    {
    }

    /** The compact program, or null when it cannot be parsed. */
    public function compact(string $source): ?string
    {
        $key = hash('sha256', self::VERSION."\0".$source);
        if (isset(self::$memory[$key])) {
            return self::$memory[$key];
        }
        $file = $this->cacheDirectory !== null ? rtrim($this->cacheDirectory, '/').'/'.$key.'.js' : null;
        if ($file !== null && is_file($file)) {
            $cached = @file_get_contents($file);
            if (is_string($cached) && $cached !== '') {
                return self::$memory[$key] = $cached;
            }
        }

        try {
            $program = Peast::latest($source, ['sourceType' => Peast::SOURCE_TYPE_SCRIPT])->parse();
            $compact = (new Renderer())->setFormatter(new class extends Compact {
                // Braces stay, so an else can never attach to a different if.
                protected $alwaysWrapBlocks = true;
            })->render($program);
        } catch (\Throwable) {
            return null;
        }
        $compact = self::license($source).$compact."\n";

        if ($file !== null) {
            // Only a speed-up: a missing or unwritable cache means compacting again.
            if (is_dir($this->cacheDirectory) || @mkdir($this->cacheDirectory, 0770, true) || is_dir($this->cacheDirectory)) {
                $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
                if (@file_put_contents($temporary, $compact) !== false && !@rename($temporary, $file)) {
                    @unlink($temporary);
                }
            }
        }

        return self::$memory[$key] = $compact;
    }

    /**
     * A compact template for a configured script: each declaration is first
     * replaced by its placeholder line, as the Terser build does, so settings
     * are inserted per request without compacting again. Null when a
     * declaration or placeholder is not found exactly once.
     *
     * @param array<string, string> $declarations source declaration => placeholder declaration
     * @param list<string> $placeholders
     */
    public function template(string $source, array $declarations, array $placeholders): ?string
    {
        foreach (array_keys($declarations) as $declaration) {
            if (substr_count($source, $declaration) !== 1) {
                return null;
            }
        }
        $template = $this->compact(strtr($source, $declarations));
        foreach ($placeholders as $placeholder) {
            if ($template === null || substr_count($template, $placeholder) !== 1) {
                return null;
            }
        }

        return $template;
    }

    /** A /*! comment at the start of the source: the script's license notice. */
    private static function license(string $source): string
    {
        return preg_match('~\A\s*(/\*!.*?\*/)~s', $source, $match) === 1 ? $match[1]."\n" : '';
    }
}
