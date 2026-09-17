<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CreateWebsiteCommand;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class CreateWebsiteCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-website-command-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->directory.'/config', 0700);
        file_put_contents($this->directory.'/config/websites.yaml', Yaml::dump([
            'operator_setting' => 'preserve-me',
            'websites' => [['name' => 'Existing', 'domain' => 'existing.example', 'token' => 'existing-token']],
        ]));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('policies')]
    public function testCreationSavesExplicitPolicyAndPreservesExistingConfiguration(array $options, array $policy): void
    {
        $tester = $this->tester(new WebsiteConfigManager($this->directory));
        $tester->setInputs(['New website', 'https://SHOP.Example.com/']);

        self::assertSame(Command::SUCCESS, $tester->execute($options));
        $saved = Yaml::parseFile($this->directory.'/config/websites.yaml');
        self::assertSame('preserve-me', $saved['operator_setting']);
        self::assertSame('existing-token', $saved['websites'][0]['token']);
        self::assertCount(2, $saved['websites']);
        $website = $saved['websites'][1];
        self::assertSame('shop.example.com', $website['domain']);
        self::assertSame($policy, $website['domain_policy']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $website['token']);
        self::assertStringContainsString($website['token'], $tester->getDisplay());
    }

    public static function policies(): iterable
    {
        yield 'exact primary by default' => [[], ['mode' => 'restricted', 'domains' => ['shop.example.com']]];
        yield 'all' => [['--allow-all-domains' => true], ['mode' => 'all', 'domains' => []]];
        yield 'multiple exact and wildcard' => [
            ['--allowed-domain' => ['SHOP.EXAMPLE.COM', '*.docs.example.com']],
            ['mode' => 'restricted', 'domains' => ['shop.example.com', '*.docs.example.com']],
        ];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsDoNotChangeConfigurationOrPrintAnInstallationSnippet(array $options): void
    {
        $before = file_get_contents($this->directory.'/config/websites.yaml');
        $tester = $this->tester(new WebsiteConfigManager($this->directory));
        $tester->setInputs(['New website', 'shop.example.com']);

        self::assertSame(Command::INVALID, $tester->execute($options));
        self::assertSame($before, file_get_contents($this->directory.'/config/websites.yaml'));
        self::assertStringNotContainsString('Website created successfully', $tester->getDisplay());
        self::assertStringNotContainsString('<script>', $tester->getDisplay());
    }

    public static function invalidOptions(): iterable
    {
        yield 'conflicting modes' => [['--allow-all-domains' => true, '--allowed-domain' => ['shop.example.com']]];
        yield 'partial wildcard' => [['--allowed-domain' => ['shop*.example.com']]];
        yield 'URL' => [['--allowed-domain' => ['https://shop.example.com']]];
        yield 'empty domain' => [['--allowed-domain' => ['']]];
    }

    public function testFailedSaveDoesNotClaimWebsiteCreationSucceeded(): void
    {
        $manager = $this->createMock(WebsiteConfigManager::class);
        $manager->expects(self::once())->method('addWebsite')->willReturn(false);
        $tester = $this->tester($manager);
        $tester->setInputs(['New website', 'shop.example.com']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringNotContainsString('Website created successfully', $tester->getDisplay());
        self::assertStringNotContainsString('<script>', $tester->getDisplay());
    }

    private function tester(WebsiteConfigManager $manager): CommandTester
    {
        $command = new CreateWebsiteCommand($manager);
        $command->setHelperSet(new HelperSet([new QuestionHelper()]));

        return new CommandTester($command);
    }
}
