<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The optional Terser builds in var/browser/ (scripts/build-js.cjs). Each
 * browser script prefers its build, but only while the build provably matches
 * the files it was made from: otherwise the server compacts the current source
 * itself (BrowserScriptCompactor), so a stale or partial build never serves
 * old code and a missing one never stops a script from loading.
 */
final class BrowserBuild
{
    /**
     * The minified template, when the manifest records the SHA-256 of exactly
     * these sources and of the template itself, and every placeholder occurs
     * exactly once; otherwise null.
     *
     * @param array<string, string> $sources        manifest hash key => the file's current contents, such as ['sourceSha256' => $source]
     * @param list<string>          $placeholders   values the caller substitutes into the template
     * @param list<string>          $templateHashAt where the manifest keeps the template's hash
     */
    public static function template(
        string $projectDir,
        string $manifestFile,
        string $templateFile,
        array $sources,
        array $placeholders,
        array $templateHashAt = ['templateSha256'],
    ): ?string {
        $directory = rtrim($projectDir, '/\\').'/var/browser';
        if (!is_readable($directory.'/'.$manifestFile) || !is_readable($directory.'/'.$templateFile)) {
            return null;
        }

        try {
            $manifest = json_decode((string) file_get_contents($directory.'/'.$manifestFile), true, flags: JSON_THROW_ON_ERROR);
            $template = file_get_contents($directory.'/'.$templateFile);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1 || !is_string($template)) {
                return null;
            }
            foreach ($sources as $key => $contents) {
                if (($manifest[$key] ?? null) !== hash('sha256', $contents)) {
                    return null;
                }
            }
            $expected = $manifest;
            foreach ($templateHashAt as $key) {
                $expected = is_array($expected) ? ($expected[$key] ?? null) : null;
            }
            if (!is_string($expected) || $expected !== hash('sha256', $template)) {
                return null;
            }
            foreach ($placeholders as $placeholder) {
                if (substr_count($template, $placeholder) !== 1) {
                    return null;
                }
            }

            return $template;
        } catch (\Throwable) {
            // An optional build must never cause a script to use stale code or
            // stop working when generated assets are absent or incomplete.
            return null;
        }
    }
}
