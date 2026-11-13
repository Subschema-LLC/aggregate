<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use App\Tools\ArchCheck\Ratchet;
use App\Tools\ArchCheck\SourceFile;
use App\Tools\ArchCheck\ZoneRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** tools/arch-check.php: the FIDES rules for new zones and the ratchet for older code. */
final class ArchCheckTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> path, code, expected problem fragment */
    public static function brokenRules(): iterable
    {
        yield 'a feature uses another feature' => [
            'src/Features/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Enroll;\nuse App\\Features\\Drop\\Drop;\nfinal class Enroll {}\n",
            'features/Enroll may not use Features',
        ];
        yield 'an entity uses another entity' => [
            'src/Entities/Course/Course.php',
            "<?php\nnamespace App\\Entities\\Course;\nuse App\\Entities\\Student\\Student;\nfinal class Course {}\n",
            'entities/Course may not use Entities',
        ];
        yield 'a feature uses code that has not moved' => [
            'src/Features/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Enroll;\nfinal class Enroll { public function __construct(private \\App\\Service\\Operations\\Operations \$o) {} }\n",
            'wrap it in an integration',
        ];
        yield 'a group use reaches another feature' => [
            'src/Features/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Enroll;\nuse App\\Features\\{Drop\\Drop, Transfer\\Transfer as Move};\nfinal class Enroll {}\n",
            'uses App\\Features\\Transfer\\Transfer',
        ];
        yield 'the core imports the database' => [
            'src/Entities/Course/Course.php',
            "<?php\nnamespace App\\Entities\\Course;\nuse Doctrine\\DBAL\\Connection;\nfinal class Course {}\n",
            'must not touch the outside world',
        ];
        yield 'the core reads the clock' => [
            'src/Features/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Enroll;\nfinal class Enroll { public function at(): \\DateTimeImmutable { return new \\DateTimeImmutable(); } }\n",
            'reads the clock',
        ];
        yield 'the core reads the environment' => [
            'src/Shared/AppSettings.php',
            "<?php\nnamespace App\\Shared;\nfinal class AppSettings { public function title(): string { return (string) getenv('APP_TITLE'); } }\n",
            'getenv() reaches the outside world',
        ];
        yield 'a class extends another' => [
            'src/Delivery/Web/EnrollPage.php',
            "<?php\nnamespace App\\Delivery\\Web;\nuse Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;\nfinal class EnrollPage extends AbstractController {}\n",
            'extends Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController; compose instead',
        ];
        yield 'a class is open' => [
            'src/Features/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Enroll;\nclass Enroll {}\n",
            'is not final',
        ];
        yield 'a file is too deep' => [
            'src/Features/Enroll/Parts/Enroll.php',
            "<?php\nnamespace App\\Features\\Enroll\\Parts;\nfinal class Enroll {}\n",
            'deeper than zone/owner/file',
        ];
        yield 'a line is too long' => [
            'src/Features/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Enroll;\nfinal class Enroll {}\n// ".str_repeat('x', 100)."\n",
            'characters (max 100)',
        ];
    }

    #[DataProvider('brokenRules')]
    public function testAZoneFileThatBreaksARuleIsReported(string $path, string $code, string $expected): void
    {
        $problems = (new ZoneRules())->check(new SourceFile($path, $code));

        self::assertStringContainsString($expected, implode("\n", $problems));
    }

    public function testAZoneFileThatKeepsTheRulesPasses(): void
    {
        $feature = new SourceFile('src/Features/Enroll/Enroll.php', <<<'PHP'
            <?php
            namespace App\Features\Enroll;
            use App\Entities\Course\Course;
            use App\Shared\Clock;
            final class Enroll
            {
                public function __construct(private Clock $clock) {}
                public function since(): \DateTimeImmutable { return new \DateTimeImmutable('2026-01-01'); }
                public function course(): string { return Course::class; }
            }
            final class EnrollRefused extends \DomainException {}
            PHP);
        $adapter = new SourceFile('src/Integrations/Database/CourseRecord.php', <<<'PHP'
            <?php
            namespace App\Integrations\Database;
            use App\Service\Operations\Operations;
            use Doctrine\ORM\Mapping as ORM;
            #[ORM\Entity(repositoryClass: CourseStore::class)]
            class CourseRecord { public function __construct(private Operations $operations) {} }
            PHP);

        self::assertSame([], (new ZoneRules())->check($feature));
        self::assertSame([], (new ZoneRules())->check($adapter));
    }

    public function testTheRatchetRejectsNewFindingsAndGrowthButNotOldOnes(): void
    {
        $ratchet = new Ratchet();
        $ratchet->add(new SourceFile('src/Service/Old.php', "<?php\nnamespace App\\Service;\nclass Old extends Base {}\n"));
        $ratchet->add(new SourceFile('src/Service/Big.php', "<?php\n".str_repeat("//\n", 240)));
        $ratchet->add(new SourceFile('src/Features/Enroll/Enroll.php', "<?php\nnamespace App\\Features\\Enroll;\nclass Enroll {}\n"));
        $baseline = [
            'app-inheritance' => ['App\\Service\\Old' => 'extends App\\Service\\Base'],
            'not-final' => ['App\\Service\\Old' => 'not final', 'App\\Service\\Gone' => 'not final'],
            'file-size' => ['src/Service/Big.php' => 230],
        ];

        $problems = implode("\n", $ratchet->compare($baseline));

        self::assertStringContainsString('src/Service/Big.php grew from 230 to 241 lines', $problems);
        self::assertStringContainsString('App\\Service\\Gone is fixed', $problems);
        self::assertStringNotContainsString('App\\Service\\Old', $problems);
        self::assertStringNotContainsString('Enroll', $problems, 'Zone code is checked by ZoneRules, not the ratchet.');
    }

    public function testUpdatingTheBaselineNeverAdmitsANewFinding(): void
    {
        $ratchet = new Ratchet();
        $ratchet->add(new SourceFile('src/Service/New.php', "<?php\nnamespace App\\Service;\nclass New_ {}\n"));
        $ratchet->add(new SourceFile('src/Service/Big.php', "<?php\n".str_repeat("//\n", 210)));

        $tightened = $ratchet->tightened(['not-final' => [], 'file-size' => ['src/Service/Big.php' => 230]]);

        self::assertSame([], $tightened['not-final']);
        self::assertSame(['src/Service/Big.php' => 211], $tightened['file-size']);
    }

    public function testTheRepositoryPassesItsOwnCheck(): void
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/tools/arch-check.php').' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
    }
}
