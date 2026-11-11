<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * A collection request builds only what collection needs. Each prefix below was
 * once loaded on every /api/receive request through an eager dependency: the
 * two-step sign-in listener and the sign-in code mailer built Twig and every
 * email provider, the dashboard access check built the permission services,
 * Doctrine's entity resolver built the ORM, and EventIngestion built the
 * message bus and the failure runner's queue.
 *
 * Runs the production kernel in a separate process, so classes loaded by other
 * tests cannot hide or cause a failure.
 */
final class CollectionRequestFootprintTest extends TestCase
{
    private const NOT_FOR_COLLECTION = [
        'Twig\\Environment',
        'Symfony\\Bridge\\Twig\\',
        'Symfony\\Component\\AssetMapper\\',
        'App\\Twig\\',
        'App\\Service\\Email\\Provider\\',
        'App\\Security\\Access\\',
        'Doctrine\\ORM\\Mapping\\',
        'Doctrine\\ORM\\UnitOfWork',
        'Symfony\\Component\\Messenger\\Middleware\\',
        'Symfony\\Component\\Messenger\\Transport\\',
        'App\\Service\\Operations\\ProcessingTasks',
    ];

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/aggregate-footprint-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->cacheDir);
    }

    public function testACollectionRequestLoadsNoDashboardMailerOrOrmServices(): void
    {
        // The first process compiles the container, which loads nearly every
        // class; the second loads only what one request needs.
        $this->runScript('--warm-up');
        $result = json_decode(trim($this->runScript()), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(400, $result['status'], 'The unconfigured token should be refused by EventIngestion: '.$result['body']);
        self::assertContains('App\\Service\\EventIngestion', $result['classes'], 'The request did not reach the collection service.');
        self::assertContains('App\\Security\\SignInCode\\SecondStepListener', $result['classes'], 'The dashboard listeners did not run.');
        $unexpected = array_values(array_filter(
            $result['classes'],
            static function (string $class): bool {
                foreach (self::NOT_FOR_COLLECTION as $prefix) {
                    if (str_starts_with($class, $prefix)) {
                        return true;
                    }
                }

                return false;
            },
        ));
        self::assertSame([], $unexpected, 'A collection request loaded services it does not need.');
    }

    private function runScript(string ...$options): string
    {
        $process = new Process(
            [\PHP_BINARY, dirname(__DIR__).'/Integration/collection-request-classes.php', $this->cacheDir, ...$options],
            dirname(__DIR__, 2),
            [
                'APP_ENV' => 'prod',
                'APP_DEBUG' => '0',
                'APP_SECRET' => 'collection-footprint-test',
                'DATABASE_URL' => 'sqlite:///:memory:',
                'MESSENGER_TRANSPORT_DSN' => 'sync://',
                'MAILER_DSN' => 'null://null',
                // The dashboard's listeners are what this test watches.
                'DASHBOARD_ENABLED' => '1',
            ],
            timeout: 180,
        );
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());

        return $process->getOutput();
    }
}
