<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\AppBranding;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class AppBrandingExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly AppBranding $branding,
    ) {}

    public function getGlobals(): array
    {
        return [
            'app_branding' => $this->branding->toArray(),
        ];
    }
}
