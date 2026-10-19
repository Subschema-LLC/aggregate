<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ApplicationSettingsController;
use App\Controller\ReportingTimezoneController;
use App\Entity\User;
use App\Service\DocumentationLinks;
use App\Service\MysqlTimeZoneTables;
use App\Service\ReportingTimezone;
use App\Service\TrackerBuilds;
use App\Service\TrackerScript;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** The reporting timezone is a section of General settings; ReportingTimezoneController saves it. */
final class GeneralSettingsReportingTimezoneTest extends KernelTestCase
{
    public function testTheSectionShowsTheZoneAndPostsToTheSaveAction(): void
    {
        $crawler = $this->page();
        $section = $crawler->filter('section#reporting-timezone');

        self::assertCount(1, $section);
        self::assertSame('Reporting timezone', trim($section->filter('h2')->text()));
        self::assertSame('/dashboard/reporting/timezone/save', $section->filter('form')->attr('action'));
        $selected = $section->filter('#reporting-timezone-zone option[selected]');
        self::assertCount(1, $selected);
        self::assertSame(self::getContainer()->get(ReportingTimezone::class)->name(), $selected->attr('value'));
        self::assertStringContainsString('day boundaries', $section->text());
        self::assertCount(0, $section->filter('pre code'), 'no MySQL command on this database');
        self::assertCount(1, $crawler->filter('#reporting-timezone-zone'), 'the select id does not clash with the section id');
    }

    public function testARefusedSaveIsShownOnceWithTheSubmittedZoneAndTheCommand(): void
    {
        $crawler = $this->page([
            'value' => 'Europe/London',
            'error' => null,
            'load_command' => 'mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root -p mysql',
        ]);
        $section = $crawler->filter('section#reporting-timezone');

        self::assertSame('Europe/London', $section->filter('#reporting-timezone-zone option[selected]')->attr('value'));
        self::assertSame('mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root -p mysql', $section->filter('[role="alert"] pre code')->text());
        self::assertNull(self::getContainer()->get('request_stack')->getCurrentRequest()->getSession()->get(ReportingTimezoneController::SESSION_REFUSED_SAVE));

        $again = $this->page([
            'value' => 'EST',
            'error' => 'reporting_timezone must be an IANA time zone name such as America/Chicago, or UTC.',
            'load_command' => null,
        ])->filter('section#reporting-timezone');
        self::assertStringContainsString('IANA time zone name', $again->filter('.notification.is-danger')->text());
    }

    /** @param array<string, ?string>|null $refused a refused save waiting in the session */
    private function page(?array $refused = null): Crawler
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = self::getContainer();
        $request = Request::create('/dashboard/settings');
        $request->setSession(new Session(new MockArraySessionStorage()));
        if ($refused !== null) {
            $request->getSession()->set(ReportingTimezoneController::SESSION_REFUSED_SAVE, $refused);
        }
        $container->get('request_stack')->push($request);
        $admin = (new User())->setUsername('admin')->setRoles(['ROLE_ADMIN'])->setPassword('unused');
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', ['ROLE_ADMIN']));

        $response = $container->get(ApplicationSettingsController::class)->applicationSettings(
            $container->get(DocumentationLinks::class),
            $container->get(TrackerBuilds::class),
            $container->get(TrackerScript::class),
            $request,
            $container->get(ReportingTimezone::class),
            $container->get(MysqlTimeZoneTables::class),
        );
        self::assertSame(200, $response->getStatusCode());

        return new Crawler((string) $response->getContent());
    }
}
