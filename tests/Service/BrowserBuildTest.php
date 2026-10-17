<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserBuild;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BrowserBuildTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-browser-build-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var/browser', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testATemplateIsUsedOnlyWhileItMatchesItsSourcesAndItself(): void
    {
        $template = 'var a=__CONFIG__;var b=__STYLES__;';
        $this->build(['format' => 1, 'sourceSha256' => hash('sha256', 'source'), 'stylesheetSha256' => hash('sha256', 'styles'), 'templateSha256' => hash('sha256', $template)], $template);
        $use = fn (array $sources): ?string => BrowserBuild::template($this->projectDir, 'manifest.json', 'script.min.js', $sources, ['__CONFIG__', '__STYLES__']);

        self::assertSame($template, $use(['sourceSha256' => 'source', 'stylesheetSha256' => 'styles']));
        self::assertNull($use(['sourceSha256' => 'changed source', 'stylesheetSha256' => 'styles']), 'the source changed after the build');
        self::assertNull($use(['sourceSha256' => 'source', 'stylesheetSha256' => 'changed styles']), 'any listed source counts');
        self::assertNull($use(['sourceSha256' => 'source', 'adapterSha256' => 'adapter']), 'a source the manifest does not record');

        file_put_contents($this->projectDir.'/var/browser/script.min.js', $template.' ');
        self::assertNull($use(['sourceSha256' => 'source', 'stylesheetSha256' => 'styles']), 'the template was edited');
    }

    public function testEachPlaceholderMustOccurExactlyOnce(): void
    {
        foreach (['var a=__CONFIG__;' => null, 'var a=__CONFIG__;var b=__CONFIG__;' => null, 'var a=__CONFIG__;var b=__STYLES__;' => true] as $template => $usable) {
            $this->build(['format' => 1, 'sourceSha256' => hash('sha256', 'source'), 'templateSha256' => hash('sha256', $template)], $template);
            $result = BrowserBuild::template($this->projectDir, 'manifest.json', 'script.min.js', ['sourceSha256' => 'source'], ['__CONFIG__', '__STYLES__']);
            self::assertSame($usable ? $template : null, $result, $template);
        }
    }

    public function testATemplateHashCanSitDeeperInTheManifestAndBrokenBuildsAreIgnored(): void
    {
        $template = 'x=__CONFIG__';
        $manifest = ['format' => 1, 'sourceSha256' => hash('sha256', 'source'), 'builds' => ['strict' => ['templateSha256' => hash('sha256', $template)]]];
        $this->build($manifest, $template);
        $use = fn (array $at): ?string => BrowserBuild::template($this->projectDir, 'manifest.json', 'script.min.js', ['sourceSha256' => 'source'], ['__CONFIG__'], $at);

        self::assertSame($template, $use(['builds', 'strict', 'templateSha256']));
        self::assertNull($use(['builds', 'full', 'templateSha256']));
        self::assertNull($use(['templateSha256']));

        $this->build(['format' => 2] + $manifest, $template);
        self::assertNull($use(['builds', 'strict', 'templateSha256']), 'an unknown manifest format');
        file_put_contents($this->projectDir.'/var/browser/manifest.json', '{not json');
        self::assertNull($use(['builds', 'strict', 'templateSha256']));
        unlink($this->projectDir.'/var/browser/manifest.json');
        self::assertNull($use(['builds', 'strict', 'templateSha256']), 'no build at all');
    }

    private function build(array $manifest, string $template): void
    {
        file_put_contents($this->projectDir.'/var/browser/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($this->projectDir.'/var/browser/script.min.js', $template);
    }
}
