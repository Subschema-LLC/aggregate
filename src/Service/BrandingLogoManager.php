<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BrandingLogoManager
{
    public const MAX_FILE_SIZE = 2 * 1024 * 1024;
    public const MAX_DIMENSION = 4096;
    public const MAX_PIXELS = 16_000_000;

    /** @var array<int, array{extension: string, mime: string}> */
    private const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_PNG => ['extension' => 'png', 'mime' => 'image/png'],
        IMAGETYPE_JPEG => ['extension' => 'jpg', 'mime' => 'image/jpeg'],
        IMAGETYPE_WEBP => ['extension' => 'webp', 'mime' => 'image/webp'],
    ];

    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
    ) {}

    /**
     * Validate and store an uploaded logo, returning its project-relative path.
     */
    public function store(UploadedFile $logo): string
    {
        if (!$logo->isValid()) {
            throw new \InvalidArgumentException('The logo upload did not complete successfully.');
        }

        $size = $logo->getSize();
        if (!is_int($size) || $size < 1 || $size > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('The logo must be no larger than 2 MiB.');
        }

        $image = @getimagesize($logo->getPathname());
        $imageType = is_array($image) ? ($image[2] ?? null) : null;
        if (!is_int($imageType) || !isset(self::ALLOWED_IMAGE_TYPES[$imageType])) {
            throw new \InvalidArgumentException('Upload a PNG, JPEG, or WebP logo. SVG and other file types are not accepted.');
        }

        $width = $image[0] ?? null;
        $height = $image[1] ?? null;
        if (!is_int($width) || !is_int($height) || $width < 1 || $height < 1
            || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            || $width > intdiv(self::MAX_PIXELS, $height)) {
            throw new \InvalidArgumentException('The logo must be at most 4096 × 4096 pixels and 16 megapixels.');
        }

        $detectedMime = $image['mime'] ?? image_type_to_mime_type($imageType);
        if ($detectedMime !== self::ALLOWED_IMAGE_TYPES[$imageType]['mime']) {
            throw new \InvalidArgumentException('The uploaded logo content does not match an allowed image type.');
        }

        $directory = $this->managedDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('The branding upload directory could not be created.');
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('The branding upload directory is not writable.');
        }

        $filename = 'logo-'.bin2hex(random_bytes(16)).'.'.self::ALLOWED_IMAGE_TYPES[$imageType]['extension'];

        try {
            $stored = $logo->move($directory, $filename);
        } catch (FileException $e) {
            throw new \RuntimeException('The logo could not be stored.', previous: $e);
        }

        @chmod($stored->getPathname(), 0640);

        return $this->managedRelativeDirectory().'/'.$filename;
    }

    /**
     * Delete only a file name created by this manager for the active environment.
     */
    public function removeManaged(?string $path): void
    {
        if (!is_string($path) || preg_match($this->managedPathPattern(), $path) !== 1) {
            return;
        }

        $absolutePath = $this->projectDir.'/'.str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_link($absolutePath) || !is_file($absolutePath)) {
            return;
        }

        if (!@unlink($absolutePath)) {
            throw new \RuntimeException('The previous managed logo could not be removed.');
        }
    }

    public function isManagedPath(mixed $path): bool
    {
        return is_string($path) && preg_match($this->managedPathPattern(), $path) === 1;
    }

    private function managedDirectory(): string
    {
        return $this->projectDir.'/'.str_replace('/', DIRECTORY_SEPARATOR, $this->managedRelativeDirectory());
    }

    private function managedRelativeDirectory(): string
    {
        return 'var/branding/'.$this->safeEnvironment();
    }

    private function managedPathPattern(): string
    {
        return '#^'.preg_quote($this->managedRelativeDirectory(), '#').'/logo-[a-f0-9]{32}\.(?:png|jpg|webp)$#D';
    }

    private function safeEnvironment(): string
    {
        $environment = preg_replace('/[^A-Za-z0-9_-]+/', '-', $this->environment) ?? '';
        $environment = trim($environment, '-');

        return $environment !== '' ? $environment : 'default';
    }
}
