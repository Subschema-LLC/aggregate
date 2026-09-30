<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\PhpRequirementResolver;
use PHPUnit\Framework\TestCase;

final class PhpRequirementResolverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/aggregate-php-req-test-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir.'/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testFallsBackToDefaultWhenNoConfigFilesExist(): void
    {
        $resolver = new PhpRequirementResolver($this->tempDir);

        self::assertSame('8.2.0', $resolver->minimumVersion());
        self::assertSame('8.2', $resolver->displayVersion());
    }

    public function testReadsFromReleaseYamlWhenAggregateYamlHasNoValue(): void
    {
        file_put_contents($this->tempDir.'/config/release.yaml', "branch: master\nminimum_php_version: '8.3'\n");

        $resolver = new PhpRequirementResolver($this->tempDir);

        self::assertSame('8.3.0', $resolver->minimumVersion());
        self::assertSame('8.3', $resolver->displayVersion());
    }

    public function testAggregateYamlOverridesReleaseYaml(): void
    {
        file_put_contents($this->tempDir.'/config/release.yaml', "branch: master\nminimum_php_version: '8.2'\n");
        file_put_contents($this->tempDir.'/config/aggregate.yaml', "minimum_php_version: '8.4'\n");

        $resolver = new PhpRequirementResolver($this->tempDir);

        self::assertSame('8.4.0', $resolver->minimumVersion());
        self::assertSame('8.4', $resolver->displayVersion());
    }

    public function testAggregateConfigLoaderActiveEnvironmentPrecedence(): void
    {
        file_put_contents($this->tempDir.'/config/release.yaml', "minimum_php_version: '8.2'\n");
        file_put_contents($this->tempDir.'/config/aggregate.yaml', "
environments:
  dev:
    minimum_php_version: '8.3'
  prod:
    minimum_php_version: '8.4'
");

        $loaderDev = new AggregateConfigLoader($this->tempDir, 'dev');
        $resolverDev = new PhpRequirementResolver($this->tempDir, $loaderDev);
        self::assertSame('8.3.0', $resolverDev->minimumVersion());

        $loaderProd = new AggregateConfigLoader($this->tempDir, 'prod');
        $resolverProd = new PhpRequirementResolver($this->tempDir, $loaderProd);
        self::assertSame('8.4.0', $resolverProd->minimumVersion());
    }

    public function testNormalizesVersionOperators(): void
    {
        self::assertSame('8.2.0', PhpRequirementResolver::normalizeVersion('>=8.2'));
        self::assertSame('8.3.1', PhpRequirementResolver::normalizeVersion('^8.3.1'));
        self::assertSame('8.4.0', PhpRequirementResolver::normalizeVersion('~8.4'));
        self::assertSame('8.2.0', PhpRequirementResolver::normalizeVersion('invalid'));
    }

    public function testCheckEvaluatesCurrentRuntimeVersion(): void
    {
        file_put_contents($this->tempDir.'/config/release.yaml', "minimum_php_version: '8.2'\n");
        $resolver = new PhpRequirementResolver($this->tempDir);

        $resultOlder = $resolver->check('8.1.99');
        self::assertFalse($resultOlder['ok']);
        self::assertStringContainsString('is too old', $resultOlder['detail']);

        $resultExact = $resolver->check('8.2.0');
        self::assertTrue($resultExact['ok']);
        self::assertStringContainsString('8.2 or newer supported', $resultExact['detail']);

        $resultNewer = $resolver->check('8.4.5');
        self::assertTrue($resultNewer['ok']);
        self::assertStringContainsString('8.2 or newer supported', $resultNewer['detail']);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $full = $path.'/'.$file;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
