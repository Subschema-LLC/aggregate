<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Moves operator edits of shipped configuration defaults into their untracked
 * config/NAME.local.yaml override, so an update can replace the default file
 * without losing the edits. The override replaces each parameter it defines.
 */
class LocalConfigOverrides
{
    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * Decide what to write for an edited default. Returns null when the override
     * already holds exactly these edits, or the content to save as the override.
     *
     * @throws \RuntimeException when the override already exists with other content
     */
    public function contentToSave(string $default, string $edited): ?string
    {
        $local = $this->overridePath($default);
        $path = $this->projectDir.'/'.$local;
        if (is_link($path)) {
            throw new \RuntimeException($local.' is a symbolic link. Replace it with a regular file before updating.');
        }
        if (!file_exists($path)) {
            return $this->annotate($default, $edited);
        }
        $existing = @file_get_contents($path);
        if (!is_string($existing)) {
            throw new \RuntimeException($local.' could not be read. Check its permissions before updating.');
        }
        if ($existing === $edited || $existing === $this->annotate($default, $edited)) {
            return null;
        }

        throw new \RuntimeException(sprintf(
            '%s has local edits, and %s already exists with different content. Merge your edits into %2$s, restore the shipped %1$s, then retry the update.',
            $default,
            $local,
        ));
    }

    /** Write an override directly (Git checkouts; release updates use a FileTransaction). */
    public function save(string $default, string $content): void
    {
        $path = $this->projectDir.'/'.$this->overridePath($default);
        $temporary = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($temporary, $content) !== strlen($content) || !@chmod($temporary, 0644) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not save '.$this->overridePath($default).'. Check write access to config/.');
        }
    }

    public function overridePath(string $default): string
    {
        return UpdatePaths::CUSTOMIZABLE_CONFIG[$default]
            ?? throw new \InvalidArgumentException('Not a customizable configuration file.');
    }

    private function annotate(string $default, string $edited): string
    {
        $note = sprintf(
            "# Moved from %s by an application update so your edits survive future updates.\n"
            ."# Parameters defined here replace the same parameters in %1\$s as a whole.\n"
            ."# Compare with %1\$s after updates to pick up new shipped entries.\n",
            $default,
        );

        return $note.$edited;
    }
}
