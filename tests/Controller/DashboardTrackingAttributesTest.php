<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DashboardController;
use App\Entity\User;
use App\Service\AggregateConfigLoader;
use App\Service\DocumentationLinks;
use App\Service\DropInScripts;
use App\Service\TrackerBuilds;
use App\Service\TrackerScript;
use App\Service\TrackingAttributes;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class DashboardTrackingAttributesTest extends KernelTestCase
{
    public function testWebsitesAndSettingsShowTheTrackingAttributesForThisNamespace(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $request = Request::create('/dashboard');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $admin = (new User())->setUsername('admin')->setRoles(['ROLE_ADMIN'])->setPassword('unused');
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'main', ['ROLE_ADMIN']));
        $names = TrackingAttributes::names($container->get(AggregateConfigLoader::class)->getWithEnvFallback('js_namespace', 'Aggregate'));
        $controller = $container->get(DashboardController::class);

        $websites = new Crawler((string) $controller->index($request, $container->get(DropInScripts::class))->getContent());
        $reference = $websites->filter('details.box')->reduce(static fn (Crawler $box): bool => str_contains($box->text(), 'Custom events and consent reference'));
        self::assertStringContainsString('<button '.$names['event'].'="plan_click" '.$names['prop'].'plan="pro" '.$names['goal'].'="signup">', $reference->filter('pre')->eq(1)->text());
        self::assertStringContainsString('never the element\'s text, link addresses or form fields', $reference->text());
        $link = $reference->filter('a[href$="#track-clicks-and-forms-with-data-attributes"]');
        self::assertCount(1, $link, 'links to the guide through its documentation topic');
        self::assertSame('noopener noreferrer', $link->attr('rel'));

        $settings = new Crawler((string) $controller->applicationSettings($container->get(DocumentationLinks::class), $container->get(TrackerBuilds::class), $container->get(TrackerScript::class))->getContent());
        self::assertStringContainsString($names['event'], $settings->filter('#js-namespace')->ancestors()->filter('.field')->first()->filter('.help')->text());
    }
}
