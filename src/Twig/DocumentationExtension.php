<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\DocumentationLinks;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** docs_url('topic'): a documentation site URL, or null when documentation links are turned off. */
final class DocumentationExtension extends AbstractExtension
{
    public function __construct(private readonly DocumentationLinks $documentation)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('docs_url', [$this->documentation, 'url']),
        ];
    }
}
