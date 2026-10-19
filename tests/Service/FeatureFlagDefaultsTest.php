<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FeatureFlags;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * New capabilities start off and hidden, so an installation never gains a
 * feature or a menu entry by updating. A feature that defaults to on has
 * graduated from beta; add it to GRADUATED only with the proposal that
 * justifies it (docs/FEATURE-FLAGS.md).
 */
final class FeatureFlagDefaultsTest extends TestCase
{
    /** Features that predate flags and stay on and shown, preserving existing installations. */
    private const GRADUATED = ['updates', 'custom_scripts'];

    public function testNewFeaturesStartOffAndHidden(): void
    {
        self::assertNotEmpty(FeatureFlags::DEFINITIONS);
        foreach (FeatureFlags::DEFINITIONS as $name => $definition) {
            if (in_array($name, self::GRADUATED, true)) {
                continue;
            }
            self::assertFalse($definition['enabled'], sprintf('The new feature "%s" must default to off.', $name));
            self::assertTrue($definition['hide_from_navigation'], sprintf('The new feature "%s" must default to hidden from navigation.', $name));
        }
    }

    public function testGraduatedFeaturesAreStillRegistered(): void
    {
        foreach (self::GRADUATED as $name) {
            self::assertArrayHasKey($name, FeatureFlags::DEFINITIONS, sprintf('Remove the retired feature "%s" from GRADUATED.', $name));
        }
    }

    /**
     * The Feature flags page tells administrators whether hiding a feature
     * changes the menu, so a definition's navigation claim must match the
     * shipped menu: a feature with navigation false has no entry there.
     */
    public function testTheMenuEntryClaimMatchesTheShippedMenu(): void
    {
        $tagged = [];
        $items = Yaml::parseFile(dirname(__DIR__, 2).'/config/navigation.yaml')['parameters']['app.main_navigation']['items'];
        foreach ($items as $item) {
            foreach ([$item, ...($item['children'] ?? [])] as $link) {
                foreach ((array) ($link['feature'] ?? []) as $feature) {
                    $tagged[$feature] = true;
                }
            }
        }

        foreach (array_keys($tagged) as $name) {
            self::assertArrayHasKey($name, FeatureFlags::DEFINITIONS, sprintf('navigation.yaml names the unregistered feature "%s".', $name));
        }
        foreach (FeatureFlags::DEFINITIONS as $name => $definition) {
            self::assertSame(
                $definition['navigation'] ?? true,
                isset($tagged[$name]),
                sprintf('The "%s" definition and config/navigation.yaml disagree about whether it has a menu entry.', $name),
            );
        }
    }

    public function testEveryDefinitionDescribesItselfForThePage(): void
    {
        foreach (FeatureFlags::DEFINITIONS as $name => $definition) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $name);
            self::assertNotSame('', trim($definition['label']), $name);
            self::assertNotSame('', trim($definition['description']), $name);
            self::assertIsBool($definition['enabled'], $name);
            self::assertIsBool($definition['hide_from_navigation'], $name);
        }
    }
}
