<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\BrandingSettingsForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\Yaml\Yaml;

final class BrandingSettingsFormTest extends TestCase
{
    private string $projectDir;
    private AggregateConfigLoader $config;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-branding-form-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['brand_name' => 'Analytics']));
        $this->config = new AggregateConfigLoader($this->projectDir, 'test');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testFieldsEqualToWhatThePageShowedAreNotWritten(): void
    {
        $form = BrandingSettingsForm::fromSubmission(new ParameterBag([
            'brand_name' => ' Analytics ', '_original_brand_name' => 'Analytics',
            'brand_logo_text' => '', '_original_brand_logo_text' => '',
            'brand_primary_color' => '#1d4ed8', '_original_brand_primary_color' => '#1D4ED8',
        ]), null, $this->config);

        self::assertSame(['settings' => [], 'upload' => null, 'remove_logo' => false], $form);
    }

    public function testChangedValuesAreReturnedThemeFirst(): void
    {
        $form = BrandingSettingsForm::fromSubmission(new ParameterBag([
            'brand_name' => 'New name', '_original_brand_name' => 'Analytics',
            'brand_primary_color' => '#123', '_original_brand_primary_color' => '#1D4ED8',
            'brand_font_family' => 'Inter, system-ui',
            'remove_brand_logo' => '1',
        ]), null, $this->config);

        self::assertSame(['brand_primary_color', 'brand_font_family', 'brand_name', 'brand_logo_text'], array_keys($form['settings']));
        self::assertSame('#112233', $form['settings']['brand_primary_color']);
        self::assertSame('New name', $form['settings']['brand_name']);
        self::assertSame('', $form['settings']['brand_logo_text'], 'a field without its original is written');
        self::assertTrue($form['remove_logo']);
        self::assertNull($form['upload']);
    }

    /** @return iterable<string, array{array<string, string>, mixed, string}> */
    public static function invalidSubmissions(): iterable
    {
        yield 'empty name' => [['brand_name' => ' '], null, 'Brand name is required.'];
        yield 'long name' => [['brand_name' => str_repeat('n', 101)], null, 'Brand name must be at most 100 characters and cannot contain control characters.'];
        yield 'logo text' => [['brand_name' => 'A', 'brand_logo_text' => "Line\nbreak"], null, 'Logo text must be at most 100 characters and cannot contain control characters.'];
        yield 'color' => [['brand_name' => 'A', 'brand_accent_color' => 'blue'], null, 'Accent color must use #RGB or #RRGGBB hexadecimal notation.'];
        yield 'font' => [['brand_name' => 'A', 'brand_heading_font_family' => 'url(evil)'], null, 'Heading font stack must contain one to eight comma-separated local/system font family names using only letters, numbers, spaces, underscores, or hyphens.'];
        yield 'contrast' => [['brand_name' => 'A', 'brand_text_color' => '#FFFFFF', 'brand_background_color' => '#FFFFFF'], null, 'Text color must have at least 4.5:1 contrast against both the page background and surface colors.'];
        yield 'upload value' => [['brand_name' => 'A'], 'not-a-file', 'The submitted logo upload is invalid.'];
    }

    /** @param array<string, string> $values */
    #[DataProvider('invalidSubmissions')]
    public function testInvalidSubmissionsAreRejectedWithThePageMessage(array $values, mixed $upload, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        BrandingSettingsForm::fromSubmission(new ParameterBag($values), $upload, $this->config);
    }
}
