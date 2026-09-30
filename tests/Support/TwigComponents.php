<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\DocumentationLinks;
use App\Twig\DocumentationExtension;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\UX\TwigComponent\ComponentAttributes;
use Symfony\UX\TwigComponent\ComponentFactory;
use Symfony\UX\TwigComponent\ComponentProperties;
use Symfony\UX\TwigComponent\ComponentRenderer;
use Symfony\UX\TwigComponent\ComponentStack;
use Symfony\UX\TwigComponent\ComponentTemplateFinder;
use Symfony\UX\TwigComponent\Twig\ComponentExtension;
use Symfony\UX\TwigComponent\Twig\ComponentLexer;
use Symfony\UX\TwigComponent\Twig\ComponentRuntime;
use Twig\Environment;
use Twig\Runtime\EscaperRuntime;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * Use the real UX renderer in isolated controller tests, without booting services or a database.
 * Also registers docs_url(), which shared components use, with a fixed documentation_url.
 */
final class TwigComponents
{
    /** @param string $documentationUrl the documentation_url setting; '' turns documentation links off */
    public static function register(Environment $twig, string $documentationUrl = DocumentationLinks::DEFAULT_URL): void
    {
        $twig->addExtension(new DocumentationExtension(FixedDocumentationLinks::create($documentationUrl)));
        $dispatcher = new EventDispatcher();
        $accessor = PropertyAccess::createPropertyAccessor();
        $factory = new ComponentFactory(
            new ComponentTemplateFinder($twig->getLoader(), 'components/'),
            new ServiceLocator([]), $accessor, $dispatcher, [], [], $twig,
        );
        $renderer = new ComponentRenderer($twig, $dispatcher, $factory, new ComponentProperties($accessor), new ComponentStack());
        $twig->addExtension(new ComponentExtension());
        $twig->setLexer(new ComponentLexer($twig));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            ComponentRuntime::class => static fn () => new ComponentRuntime($renderer, new ServiceLocator([])),
        ]));
        $twig->getRuntime(EscaperRuntime::class)->addSafeClass(ComponentAttributes::class, ['html']);
    }
}
