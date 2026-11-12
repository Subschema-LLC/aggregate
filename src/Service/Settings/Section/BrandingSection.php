<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use App\Service\BrandingSettingsForm;
use App\Service\BrandingSettingsService;
use App\Service\BrandingTheme;
use App\Service\Settings\AbstractSection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Branding, through the Branding page's own form and save: the name, logo
 * text, colors and fonts, and brand_logo, a PNG, JPEG or WebP file to upload
 * (its path on this server; an empty value removes the uploaded logo). A
 * field the command was not given is submitted unchanged, so it is not
 * written, as on the page.
 */
final class BrandingSection extends AbstractSection
{
    public const LOGO = 'brand_logo';
    private const TEXT = ['brand_name', 'brand_logo_text'];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly BrandingSettingsService $branding,
        private readonly AppBranding $appBranding,
    ) {
    }

    public function name(): string
    {
        return 'branding';
    }

    public function page(): string
    {
        return 'Administration > Branding';
    }

    public function keys(): array
    {
        return [...self::TEXT, ...BrandingTheme::CONFIG_KEYS, self::LOGO];
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);
        $this->appBranding->reset();
        $branding = $this->appBranding;
        $theme = (new BrandingTheme($this->config))->toArray();
        $values = ['brand_name' => $branding->getName(), 'brand_logo_text' => $branding->getLogoText()];
        foreach (BrandingTheme::CONFIG_KEYS as $key) {
            $values[$key] = $theme[substr($key, strlen('brand_'))] ?? null;
        }
        $values[self::LOGO] = $branding->getLogoPath() ?? '';

        return $values;
    }

    public function environmentOverrides(?string $target = null): array
    {
        $this->assertTarget($target);

        return array_values(array_filter(
            [...self::TEXT, ...BrandingTheme::CONFIG_KEYS, 'brand_logo_path'],
            fn (string $key): bool => $this->config->hasEnvironmentOverride($key, allowEmpty: true),
        ));
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $current = $this->values();
        $fields = [];
        foreach ([...self::TEXT, ...BrandingTheme::CONFIG_KEYS] as $key) {
            $fields[$key] = (string) (array_key_exists($key, $changes) ? $changes[$key] : $current[$key]);
            $fields['_original_'.$key] = (string) $current[$key];
        }
        $upload = null;
        $temporary = null;
        if (array_key_exists(self::LOGO, $changes)) {
            $path = is_string($changes[self::LOGO]) ? trim($changes[self::LOGO]) : '';
            if ($path === '') {
                $fields['remove_brand_logo'] = '1';
            } else {
                if (!is_file($path) || !is_readable($path)) {
                    throw new \InvalidArgumentException('brand_logo must be the path of a readable image file on this server, or empty to remove the logo.');
                }
                // The upload is moved into place; the operator's own file stays where it is.
                $temporary = tempnam(sys_get_temp_dir(), 'aggregate-logo-');
                if ($temporary === false || !@copy($path, $temporary)) {
                    throw new \RuntimeException('The logo could not be read into a temporary file.');
                }
                $upload = new UploadedFile($temporary, basename($path), null, \UPLOAD_ERR_OK, true);
            }
        }

        try {
            if ($check) {
                BrandingSettingsForm::fromSubmission(new ParameterBag($fields), $upload, $this->config);

                return [self::done(true, '')];
            }
            $result = $this->branding->save(new ParameterBag($fields), $upload);
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
        if (!$result['saved']) {
            return $result['warnings'];
        }

        return [...$result['warnings'], 'Branding updated successfully.'];
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
