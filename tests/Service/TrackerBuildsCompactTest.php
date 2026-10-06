<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserScriptCompactor;
use App\Service\TrackerBuilds;
use App\Service\TrackerScript;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The tracker builds this server compacts when no Terser build matches must
 * behave like the readable source. The scripted visits in
 * tests/JavaScript/tracker-builds.test.js run against them in Node.
 */
final class TrackerBuildsCompactTest extends TestCase
{
    public function testServerCompactedBuildsBehaveLikeTheReadableSource(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('Node.js runs the tracker; npm run test:js checks the Terser builds the same way.');
        }

        $projectDir = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir().'/aggregate-compact-builds-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $source = (string) file_get_contents($projectDir.'/public/aggregate.js');
            foreach (TrackerBuilds::SWITCHES as $build => $switches) {
                $template = (new BrowserScriptCompactor())->template($source, TrackerScript::DECLARATIONS, TrackerScript::PLACEHOLDERS, $switches);
                self::assertNotNull($template, $build);
                file_put_contents($directory.'/'.$build.'.js', $template);
            }

            $process = new Process([$node, '--test', 'tests/JavaScript/tracker-builds.test.js'], $projectDir, ['AGGREGATE_COMPACT_BUILDS_DIR' => $directory], timeout: 120);
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            self::assertMatchesRegularExpression('/^# fail 0$/m', $process->getOutput());
        } finally {
            (new Filesystem())->remove($directory);
        }
    }
}
