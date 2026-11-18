<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use App\Tools\ArchCheck\Ratchet;
use App\Tools\ArchCheck\SourceFile;
use App\Tools\ArchCheck\ZoneFiles;
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
            'src/Features/Courses/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Courses\\Enroll;\nuse App\\Features\\Courses\\Drop\\Drop;\nfinal class Enroll {}\n",
            'features/Courses/Enroll may not use Features',
        ];
        yield 'an entity uses another entity' => [
            'src/Entities/Course/Course.php',
            "<?php\nnamespace App\\Entities\\Course;\nuse App\\Entities\\Student\\Student;\nfinal class Course {}\n",
            'entities/Course may not use Entities',
        ];
        yield 'a feature uses code that has not moved' => [
            'src/Features/Courses/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Courses\\Enroll;\nfinal class Enroll { public function __construct(private \\App\\Service\\Operations\\Operations \$o) {} }\n",
            'wrap it in an integration',
        ];
        yield 'a group use reaches another feature' => [
            'src/Features/Courses/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Courses\\Enroll;\nuse App\\Features\\Courses\\{Drop\\Drop, Transfer\\Transfer as Move};\nfinal class Enroll {}\n",
            'uses App\\Features\\Courses\\Transfer\\Transfer',
        ];
        yield 'the core imports the database' => [
            'src/Entities/Course/Course.php',
            "<?php\nnamespace App\\Entities\\Course;\nuse Doctrine\\DBAL\\Connection;\nfinal class Course {}\n",
            'must not touch the outside world',
        ];
        yield 'the core reads the clock' => [
            'src/Features/Courses/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Courses\\Enroll;\nfinal class Enroll { public function at(): \\DateTimeImmutable { return new \\DateTimeImmutable(); } }\n",
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
            'src/Features/Courses/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Courses\\Enroll;\nclass Enroll {}\n",
            'is not final',
        ];
        yield 'a feature outside a group' => [
            'src/Features/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Enroll;\nfinal class Enroll {}\n",
            'a feature sits in src/Features/<Group>/<UseCase>/<File>',
        ];
        yield 'a feature uses another in its group' => [
            'src/Features/Courses/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Courses\\Enroll;\nuse App\\Features\\Courses\\Drop\\Drop;\nfinal class Enroll {}\n",
            'features/Courses/Enroll may not use Features',
        ];
        yield 'a file is too deep' => [
            'src/Entities/Course/Parts/Course.php',
            "<?php\nnamespace App\\Entities\\Course\\Parts;\nfinal class Course {}\n",
            'deeper than zone/owner/file',
        ];
        yield 'a zone class mixes in an app trait' => [
            'src/Delivery/Alerts/AlertsPage.php',
            "<?php\nnamespace App\\Delivery\\Alerts;\nuse App\\Controller\\DashboardAccess;\nfinal class AlertsPage { use DashboardAccess; }\n",
            'uses trait App\\Controller\\DashboardAccess; compose instead',
        ];
        yield 'the core mixes in a framework trait' => [
            'src/Features/Courses/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Courses\\Enroll;\nfinal class Enroll { use \\Vendor\\Helpful; }\n",
            'a framework trait stays at the edge',
        ];
        yield 'a trait in a zone' => [
            'src/Shared/Helpers.php',
            "<?php\nnamespace App\\Shared;\ntrait Helpers {}\n",
            'is a trait; compose instead',
        ];
        yield 'the core asks the container' => [
            'src/Features/Courses/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Courses\\Enroll;\nuse Symfony\\Component\\DependencyInjection\\Attribute\\Autowire;\nfinal class Enroll {}\n",
            'must not depend on the container',
        ];
        yield 'a line is too long' => [
            'src/Features/Courses/Enroll/Enroll.php',
            "<?php\nnamespace App\\Features\\Courses\\Enroll;\nfinal class Enroll {}\n// ".str_repeat('x', 100)."\n",
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
        $feature = new SourceFile('src/Features/Courses/Enroll/Enroll.php', <<<'PHP'
            <?php
            namespace App\Features\Courses\Enroll;
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

    /** @return iterable<string, array{string, string, string}> path, contents, expected problem fragment */
    public static function brokenFileRules(): iterable
    {
        yield 'a snake_case folder' => [
            'src/Features/Alerts/save_rule/SaveRule.php', '<?php', '"save_rule" is not PascalCase',
        ];
        yield 'a lower-case view' => [
            'src/Features/Alerts/SaveRule/form.html.twig', '', '"form" is not PascalCase',
        ];
        yield 'a style element' => [
            'src/Features/Alerts/SaveRule/SaveRule.html.twig', "<p>Hi</p>\n<style>p {}</style>", ':2: a <style> element',
        ];
        yield 'a style attribute' => [
            'src/Delivery/Alerts/Alerts.html.twig', '<p style="color: red">x</p>', 'a style attribute',
        ];
        yield 'a script element' => [
            'src/Delivery/Alerts/Alerts.html.twig', '<script src="/x.js"></script>', 'a <script> element',
        ];
        yield 'an inline handler' => [
            'src/Features/Alerts/SaveRule/SaveRule.html.twig', '<button onclick="go()">Go</button>', 'inline event handler',
        ];
        yield 'an entity name' => [
            'src/Features/Alerts/SaveRule/SaveRule.html.twig',
            "{{ constant('App\\\\Entities\\\\AlertRule\\\\AlertRule::WINDOW_DAY') }}",
            'names an entity',
        ];
        yield 'another feature\'s view' => [
            'src/Features/Alerts/SaveRule/SaveRule.html.twig',
            "{% include '@Features/Alerts/ListAlerts/ListAlerts.html.twig' %}",
            'renders @Features/Alerts/ListAlerts/ListAlerts.html.twig',
        ];
        yield 'a feature view that extends its own template' => [
            'src/Features/Alerts/SaveRule/SaveRule.html.twig', "{% extends '@Features/Alerts/SaveRule/Base.html.twig' %}", 'extends nothing',
        ];
        yield 'a page template' => [
            'src/Features/Alerts/SaveRule/SaveRule.html.twig', "{% include 'alerts/index.html.twig' %}", 'renders alerts/index.html.twig',
        ];
    }

    #[DataProvider('brokenFileRules')]
    public function testAZoneFileThatBreaksAFileRuleIsReported(
        string $path,
        string $contents,
        string $expected,
    ): void {
        $problems = (new ZoneFiles())->check($path, $contents);

        self::assertStringContainsString($expected, implode("\n", $problems));
    }

    public function testViewsThatKeepTheRulesPass(): void
    {
        $feature = <<<'TWIG'
            {% include '@Features/Alerts/SaveRule/SaveRuleFields.html.twig' with {form: form} %}
            <p class="hint">{{ view.description }}</p>
            TWIG;
        $page = <<<'TWIG'
            {% extends 'base.html.twig' %}
            {% block body %}{{ include('@Features/Alerts/ListAlerts/ListAlerts.html.twig') }}{% endblock %}
            TWIG;
        $files = new ZoneFiles();

        self::assertSame([], $files->check('src/Features/Alerts/SaveRule/SaveRule.html.twig', $feature));
        self::assertSame([], $files->check('src/Delivery/Alerts/Alerts.html.twig', $page));
        self::assertSame([], $files->check('src/Features/Alerts/SaveRule/SaveRule.css', 'p { color: red; }'));
        self::assertSame([], $files->check('src/Service/Old/old_file.php', '<?php'));
    }

    public function testTheRatchetRejectsNewFindingsAndGrowthButNotOldOnes(): void
    {
        $ratchet = new Ratchet();
        $ratchet->add(new SourceFile('src/Service/Old.php', "<?php\nnamespace App\\Service;\nclass Old extends Base {}\n"));
        $ratchet->add(new SourceFile('src/Service/Big.php', "<?php\n".str_repeat("//\n", 240)));
        $ratchet->add(new SourceFile('src/Features/Courses/Enroll/Enroll.php', "<?php\nnamespace App\\Features\\Courses\\Enroll;\nclass Enroll {}\n"));
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

    public function testTheRatchetHoldsTraitsIntegrationNamesAndUnboundContracts(): void
    {
        $ratchet = new Ratchet("services:\n    App\\Entities\\Course\\Courses: '@App\\Integrations\\Database\\DbCourses'\n");
        $ratchet->add(new SourceFile('src/Controller/Page.php', "<?php\nnamespace App\\Controller;\nuse App\\Integrations\\Database\\DbCourses;\nfinal class Page { use Access; }\n"));
        $ratchet->add(new SourceFile('src/Entities/Course/Courses.php', "<?php\nnamespace App\\Entities\\Course;\ninterface Courses {}\n"));
        $ratchet->add(new SourceFile('src/Entities/Course/Clock.php', "<?php\nnamespace App\\Entities\\Course;\ninterface Clock {}\n"));

        $findings = $ratchet->findings();

        self::assertSame(['App\\Controller\\Page' => 'uses App\\Controller\\Access'], $findings['app-trait']);
        self::assertSame(['src/Controller/Page.php' => 'names App\\Integrations\\Database\\DbCourses'], $findings['names-integration']);
        self::assertSame(['App\\Entities\\Course\\Clock' => 'not bound in config/wiring.yaml'], $findings['unbound-contract']);
        self::assertStringContainsString('app-trait: App\\Controller\\Page (uses App\\Controller\\Access) is new', implode("\n", $ratchet->compare([])));
    }

    public function testADataFileListedWithItsReasonSkipsTheSizeLimit(): void
    {
        $path = (string) array_key_first(ZoneRules::SIZE_EXEMPT);
        $big = new SourceFile($path, "<?php\n".str_repeat("//\n", 300));
        $ratchet = new Ratchet();
        $ratchet->add($big);

        self::assertNotSame('', ZoneRules::SIZE_EXEMPT[$path], 'Each exemption gives its reason.');
        self::assertSame([], $ratchet->findings()['file-size']);
    }

    public function testANewFileOutsideTheZonesIsRejected(): void
    {
        $ratchet = new Ratchet();
        $ratchet->add(new SourceFile('src/Service/Listed.php', "<?php\nnamespace App\\Service;\nfinal class Listed {}\n"));
        $ratchet->add(new SourceFile('src/Service/Added.php', "<?php\nnamespace App\\Service;\nfinal class Added {}\n"));
        $ratchet->add(new SourceFile('src/Kernel.php', "<?php\nnamespace App;\nfinal class Kernel {}\n"));
        $ratchet->add(new SourceFile('src/Features/Courses/Enroll/Enroll.php', "<?php\nnamespace App\\Features\\Courses\\Enroll;\nfinal class Enroll {}\n"));
        $baseline = ['outside-zone' => ['src/Service/Listed.php' => 'not in a zone', 'src/Service/Moved.php' => 'not in a zone']];

        $problems = implode("\n", $ratchet->compare($baseline));

        self::assertStringContainsString('src/Service/Added.php is a new file outside the zones', $problems);
        self::assertStringContainsString('src/Service/Moved.php is fixed', $problems);
        self::assertStringNotContainsString('Listed', $problems);
        self::assertStringNotContainsString('Kernel', $problems, 'Symfony expects the kernel in src/.');
        self::assertStringNotContainsString('Enroll', $problems);
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
