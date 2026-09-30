<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProjectDirEnvVarProcessor;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

final class ProjectDirEnvVarProcessorTest extends TestCase
{
    public function testFillsInTheProjectDirectoryForTheDocumentedSqliteDefault(): void
    {
        $url = $this->resolve('sqlite:///%kernel.project_dir%/var/data.db', '/var/www/vhosts/example.com/analytics');

        self::assertSame('sqlite:////var/www/vhosts/example.com/analytics/var/data.db', $url);
        self::assertSame('/var/www/vhosts/example.com/analytics/var/data.db', (new DsnParser(['sqlite' => 'pdo_sqlite']))->parse($url)['path']);
    }

    public function testLeavesEncodedPasswordsAndOtherPercentSignsAlone(): void
    {
        $url = 'mysql://user:p%40ss%25word%24@localhost:3306/db?serverVersion=8.0.39';

        self::assertSame($url, $this->resolve($url, '/srv/app'));
        self::assertSame('%other_parameter%', $this->resolve('%other_parameter%', '/srv/app'));
    }

    public function testIsRegisteredForTheDoctrineConnection(): void
    {
        self::assertSame(['project_dir' => 'string'], ProjectDirEnvVarProcessor::getProvidedTypes());
        self::assertStringContainsString("'%env(project_dir:DATABASE_URL)%'", (string) file_get_contents(dirname(__DIR__, 2).'/config/packages/doctrine.yaml'));
    }

    private function resolve(string $value, string $projectDir): string
    {
        return (new ProjectDirEnvVarProcessor($projectDir))->getEnv('project_dir', 'DATABASE_URL', static fn (): string => $value);
    }
}
