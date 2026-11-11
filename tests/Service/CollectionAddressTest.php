<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\CollectionAddress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class CollectionAddressTest extends TestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['APP_HOST'], $_SERVER['APP_HOST'], $_ENV['COLLECTION_BASE_URL'], $_SERVER['COLLECTION_BASE_URL']);
        $this->directory = sys_get_temp_dir().'/aggregate-collection-address-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory.'/config');
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testEmptyMeansTheApplicationHostAsBefore(): void
    {
        $address = $this->address(['app_host' => 'https://analytics.example.test/']);

        self::assertSame('', $address->configuredValue());
        self::assertSame('https://analytics.example.test', $address->baseUrl());
        self::assertNull($this->address([])->baseUrl(), 'neither is set');
        self::assertSame('http://localhost', $this->address([])->baseUrl('http://localhost'));
    }

    public function testASetAddressIsUsedAndKeepsItsSubdirectory(): void
    {
        $address = $this->address(['app_host' => 'https://analytics.example.test', 'collection_base_url' => 'https://collect.example.test/aggregate/']);

        self::assertSame('https://collect.example.test/aggregate', $address->baseUrl());
    }

    public function testAnInvalidAddressNeverFallsBackToTheApplicationHost(): void
    {
        // A typo must not send snippets to the administration hostname.
        $address = $this->address(['app_host' => 'https://analytics.example.test', 'collection_base_url' => 'collect.example.test']);

        self::assertSame('collect.example.test', $address->configuredValue());
        self::assertNull($address->baseUrl());
    }

    public function testTheEnvironmentTakesPrecedence(): void
    {
        $_ENV['COLLECTION_BASE_URL'] = 'https://env-collect.example.test';
        $address = $this->address(['app_host' => 'https://analytics.example.test', 'collection_base_url' => 'https://yaml-collect.example.test']);

        self::assertTrue($address->hasEnvironmentOverride());
        self::assertSame('https://env-collect.example.test', $address->baseUrl());
    }

    public function testNormalizeAcceptsEmptyOrAPlainHttpUrl(): void
    {
        self::assertSame('', CollectionAddress::normalize(''));
        self::assertSame('', CollectionAddress::normalize('  '));
        self::assertSame('', CollectionAddress::normalize(null));
        self::assertSame('https://collect.example.test', CollectionAddress::normalize(' https://collect.example.test/ '));

        foreach (['collect.example.test', 'ftp://collect.example.test', 'https://user:pass@collect.example.test', 'https://collect.example.test/?a=1', 'https://collect.example.test/#x', 'javascript:alert(1)'] as $invalid) {
            try {
                CollectionAddress::normalize($invalid);
                self::fail($invalid.' was accepted');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(CollectionAddress::RULE, $exception->getMessage());
            }
        }
    }

    private function address(array $config): CollectionAddress
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', Yaml::dump($config));

        return new CollectionAddress(new AggregateConfigLoader($this->directory, 'test'));
    }
}
