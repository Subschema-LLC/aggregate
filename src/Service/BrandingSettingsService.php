<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Saves the Branding page's form, for the page and app:config alike: the
 * form's checks (BrandingSettingsForm), then the logo stored before the YAML
 * changes and the previous managed logo removed after them, so a failed save
 * never leaves the YAML pointing at a missing file.
 */
final class BrandingSettingsService
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly BrandingLogoManager $logos,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{saved: bool, warnings: list<string>} saved is false when
     *                                                     nothing could change
     *
     * @throws \InvalidArgumentException with a message for the operator; nothing is saved
     * @throws \RuntimeException         with a message for the operator when storing fails
     */
    public function save(ParameterBag $values, mixed $uploadedLogo): array
    {
        $form = BrandingSettingsForm::fromSubmission($values, $uploadedLogo, $this->config);
        $upload = $form['upload'];
        $removeLogo = $form['remove_logo'];
        $hasLogoMutation = $upload instanceof UploadedFile || $removeLogo;

        $oldLogoPath = '';
        $newLogoPath = '';
        if ($hasLogoMutation) {
            $configuredPath = $this->config->get('brand_logo_path', '');
            $oldLogoPath = is_string($configuredPath) ? trim($configuredPath) : '';
            $newLogoPath = $removeLogo ? '' : $oldLogoPath;
        }
        $storedLogoPath = null;

        if ($upload instanceof UploadedFile) {
            try {
                $storedLogoPath = $this->logos->store($upload);
                $newLogoPath = $storedLogoPath;
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to store a branding logo.', ['exception' => $e]);

                throw new \RuntimeException('The logo could not be stored. Check the application logs and var/branding permissions.', 0, $e);
            }
        }

        $settings = $form['settings'];
        if ($hasLogoMutation) {
            $settings['brand_logo_path'] = $newLogoPath;
        }
        if ($settings === []) {
            return ['saved' => false, 'warnings' => ['No branding changes were submitted; environment-controlled values were left unchanged.']];
        }

        try {
            $this->config->setMany($settings);
        } catch (\Throwable $e) {
            if ($storedLogoPath !== null) {
                try {
                    $this->logos->removeManaged($storedLogoPath);
                } catch (\Throwable $cleanupError) {
                    $this->logger->warning('Failed to remove an uncommitted branding logo.', ['exception' => $cleanupError]);
                }
            }
            $this->logger->error('Failed to save branding settings.', ['exception' => $e]);

            throw new \RuntimeException('Branding settings could not be saved. Check the application logs.', 0, $e);
        }

        $warnings = [];
        if ($hasLogoMutation && $oldLogoPath !== '' && $oldLogoPath !== $newLogoPath) {
            try {
                $this->logos->removeManaged($oldLogoPath);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to remove the previous managed branding logo.', ['exception' => $e]);
                $warnings[] = 'Branding was saved, but the previous managed logo could not be removed.';
            }
        }

        return ['saved' => true, 'warnings' => $warnings];
    }
}
