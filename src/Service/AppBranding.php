<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Service\ResetInterface;

final class AppBranding implements ResetInterface
{
    public const DEFAULT_NAME = 'Aggregate Analytics';

    public const MAX_FILE_SIZE = 2 * 1024 * 1024;
    private const MAX_DIMENSION = 4096;
    private const MAX_PIXELS = 16_000_000;

    /** @var array<int, string> */
    private const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    private bool $logoCacheInitialized = false;
    private ?string $logoCacheKey = null;

    /** @var array{path: string, mime_type: string, version: string, content: string, last_modified: int|null}|null */
    private ?array $cachedLogo = null;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly string $projectDir,
        private readonly array $mainNavigation = [],
    ) {}

    public function getName(): string
    {
        $name = $this->normalizeText($this->getConfiguredValue('brand_name'));

        return $name !== null && $name !== '' ? $name : $this->getLegacyName();
    }

    public function getLogoText(): string
    {
        return $this->resolveLogoText($this->getName(), $this->hasLogo());
    }

    public function hasLogo(): bool
    {
        return $this->getValidatedLogo() !== null;
    }

    public function getLogoPath(): ?string
    {
        return $this->getValidatedLogo()['path'] ?? null;
    }

    public function getLogoMimeType(): ?string
    {
        return $this->getValidatedLogo()['mime_type'] ?? null;
    }

    public function getLogoVersion(): ?string
    {
        return $this->getValidatedLogo()['version'] ?? null;
    }

    /**
     * @return array{path: string, mime_type: string, version: string, content: string, last_modified: int|null}|null
     */
    public function getValidatedLogo(): ?array
    {
        try {
            $configuredPath = $this->getConfiguredValue('brand_logo_path');
            $path = $this->resolveLogoPath($configuredPath);
            $cacheKey = $this->createLogoCacheKey($configuredPath, $path);
            if ($this->logoCacheInitialized && $cacheKey === $this->logoCacheKey) {
                return $this->cachedLogo;
            }

            if ($path === null) {
                return $this->cacheLogo($cacheKey, null);
            }

            clearstatcache(true, $path);
            if (!is_file($path) || !is_readable($path)) {
                return $this->cacheLogo($cacheKey, null);
            }

            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return $this->cacheLogo($cacheKey, null);
            }

            try {
                $stat = fstat($handle);
                $content = stream_get_contents($handle, self::MAX_FILE_SIZE + 1);
            } finally {
                fclose($handle);
            }

            if (!is_array($stat)
                || !is_string($content)
                || $content === ''
                || strlen($content) > self::MAX_FILE_SIZE) {
                return $this->cacheLogo($cacheKey, null);
            }

            $image = @getimagesizefromstring($content);
            if (!is_array($image)) {
                return $this->cacheLogo($cacheKey, null);
            }

            $imageType = $image[2] ?? null;
            if (!is_int($imageType) || !isset(self::ALLOWED_IMAGE_TYPES[$imageType])) {
                return $this->cacheLogo($cacheKey, null);
            }

            $width = $image[0] ?? null;
            $height = $image[1] ?? null;
            if (!is_int($width) || !is_int($height)
                || $width < 1 || $height < 1
                || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
                || $width > intdiv(self::MAX_PIXELS, $height)) {
                return $this->cacheLogo($cacheKey, null);
            }

            $mimeType = self::ALLOWED_IMAGE_TYPES[$imageType];
            $detectedMimeType = $image['mime'] ?? image_type_to_mime_type($imageType);
            if ($detectedMimeType !== $mimeType) {
                return $this->cacheLogo($cacheKey, null);
            }

            $version = hash('sha256', $content);
            $lastModified = $stat['mtime'] ?? null;

            return $this->cacheLogo($cacheKey, [
                'path' => $path,
                'mime_type' => $mimeType,
                'version' => $version,
                'content' => $content,
                'last_modified' => is_int($lastModified) ? $lastModified : null,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{name: string, logo_text: string, has_logo: bool, logo_version: string|null, name_overridden: bool, logo_text_overridden: bool, logo_path_overridden: bool}
     */
    public function toArray(): array
    {
        $name = $this->getName();
        $logo = $this->getValidatedLogo();

        return [
            'name' => $name,
            'logo_text' => $this->resolveLogoText($name, $logo !== null),
            'has_logo' => $logo !== null,
            'logo_version' => $logo['version'] ?? null,
            'name_overridden' => $this->hasEnvironmentOverride('brand_name'),
            'logo_text_overridden' => $this->hasEnvironmentOverride('brand_logo_text'),
            'logo_path_overridden' => $this->hasEnvironmentOverride('brand_logo_path'),
        ];
    }

    public function reset(): void
    {
        $this->logoCacheInitialized = false;
        $this->logoCacheKey = null;
        $this->cachedLogo = null;
    }

    private function resolveLogoText(string $name, bool $hasLogo): string
    {
        $logoText = $this->normalizeText($this->getConfiguredValue('brand_logo_text'));
        if ($logoText === null) {
            return $name;
        }

        return $logoText !== '' || $hasLogo ? $logoText : $name;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (!mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function getConfiguredValue(string $key): mixed
    {
        try {
            return $this->config->getWithEnvFallback($key, allowEmpty: true);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getLegacyName(): string
    {
        $legacyName = $this->normalizeText($this->mainNavigation['brand']['label'] ?? null);

        return $legacyName !== null && $legacyName !== '' ? $legacyName : self::DEFAULT_NAME;
    }

    private function hasEnvironmentOverride(string $key): bool
    {
        try {
            return $this->config->hasEnvironmentOverride($key, allowEmpty: true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveLogoPath(mixed $configuredPath): ?string
    {
        if (!is_string($configuredPath)) {
            return null;
        }

        $configuredPath = trim($configuredPath);
        if ($configuredPath === '' || strlen($configuredPath) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $configuredPath) === 1
            || $this->isRemoteOrDevicePath($configuredPath)) {
            return null;
        }

        $isWindowsAbsolutePath = preg_match('/^[A-Za-z]:[\\\\\/]/D', $configuredPath) === 1;
        $isAbsolutePath = str_starts_with($configuredPath, '/') || $isWindowsAbsolutePath;
        $projectRoot = realpath($this->projectDir);
        if (!$isAbsolutePath && $projectRoot === false) {
            return null;
        }

        $candidate = $isAbsolutePath
            ? $configuredPath
            : $projectRoot.DIRECTORY_SEPARATOR.$configuredPath;
        $resolvedPath = realpath($candidate);
        if ($resolvedPath === false) {
            return null;
        }

        if (!$isAbsolutePath && !$this->isWithinProjectRoot($resolvedPath, $projectRoot)) {
            return null;
        }

        return $resolvedPath;
    }

    private function createLogoCacheKey(mixed $configuredPath, ?string $resolvedPath): string
    {
        if (!is_string($configuredPath)) {
            return 'invalid:'.get_debug_type($configuredPath);
        }

        if ($resolvedPath === null) {
            return 'invalid:'.hash('sha256', $configuredPath);
        }

        clearstatcache(true, $resolvedPath);
        $stat = @stat($resolvedPath);
        $metadata = $stat === false ? null : [
            $stat['dev'] ?? null,
            $stat['ino'] ?? null,
            $stat['mode'] ?? null,
            $stat['size'] ?? null,
            $stat['mtime'] ?? null,
            $stat['ctime'] ?? null,
            is_readable($resolvedPath),
        ];

        return hash('sha256', $configuredPath."\0".$resolvedPath."\0".json_encode($metadata));
    }

    /**
     * @param array{path: string, mime_type: string, version: string, content: string, last_modified: int|null}|null $logo
     *
     * @return array{path: string, mime_type: string, version: string, content: string, last_modified: int|null}|null
     */
    private function cacheLogo(string $cacheKey, ?array $logo): ?array
    {
        $this->logoCacheInitialized = true;
        $this->logoCacheKey = $cacheKey;
        $this->cachedLogo = $logo;

        return $logo;
    }

    private function isRemoteOrDevicePath(string $path): bool
    {
        $portablePath = str_replace('\\', '/', $path);
        if (str_starts_with($portablePath, '//')
            || preg_match('#^/(?:\?\?|Device|GLOBALROOT)(?:/|$)#iD', $portablePath) === 1) {
            return true;
        }

        $isWindowsAbsolutePath = preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
        if (!$isWindowsAbsolutePath && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $path) === 1) {
            return true;
        }

        if (preg_match('#^/dev(?:/|$)#D', $path) === 1) {
            return true;
        }

        $pathWithoutDrive = $isWindowsAbsolutePath ? substr($path, 2) : $path;
        if (str_contains($pathWithoutDrive, ':')) {
            return true;
        }

        foreach (preg_split('#[\\\\/]#', $pathWithoutDrive) ?: [] as $segment) {
            $segment = rtrim($segment, ". ");
            if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:[.:].*)?$/iD', $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isWithinProjectRoot(string $path, string $projectRoot): bool
    {
        $path = str_replace('\\', '/', $path);
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $projectRoot = strtolower($projectRoot);
        }

        return $path === $projectRoot || str_starts_with($path, $projectRoot.'/');
    }
}
