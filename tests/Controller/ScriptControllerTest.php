<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ScriptController;
use App\Service\AggregateConfigLoader;
use App\Service\InternalTrafficSettings;
use PHPUnit\Framework\TestCase;

final class ScriptControllerTest extends TestCase
{
    public function testConfiguredMarkerIsPublicButShareTokenAndOtherConfigurationStayPrivate(): void
    {
        $settings = [
            'js_namespace' => 'CompanyAnalytics',
            'internal_traffic_storage' => 'local_storage',
            'internal_traffic_name' => 'companyStaff',
            'internal_traffic_value' => 'staff',
            'internal_traffic_cookie_domain' => '.example.com',
            'internal_traffic_share_token' => str_repeat('private-share-token-', 3),
            'mail_password' => 'private-mail-password',
        ];
        $response = $this->response($settings);
        $script = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/javascript', $response->headers->get('Content-Type'));
        self::assertStringContainsString('must-revalidate', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('var namespace = "CompanyAnalytics";', $script);
        self::assertSame([
            'storage' => 'local_storage',
            'name' => 'companyStaff',
            'value' => 'staff',
            'cookieDomain' => '.example.com',
        ], $this->browserConfig($script));
        self::assertStringNotContainsString($settings['internal_traffic_share_token'], $script);
        self::assertStringNotContainsString($settings['mail_password'], $script);
        self::assertStringNotContainsString('internal_traffic_share_token', $script);
    }

    public function testDefaultsUseTheDocumentedCookieNameAndStringValue(): void
    {
        self::assertSame([
            'storage' => 'cookie',
            'name' => 'orgInternalTraffic',
            'value' => 'true',
            'cookieDomain' => '',
        ], $this->browserConfig((string) $this->response([])->getContent()));
    }

    public function testJavaScriptSensitiveConfigurationIsEncodedWithoutReplacementExpansion(): void
    {
        $namespace = "Company\n'</script>\"\\$1";
        $markerValue = "staff'</script>\"&\\$1";
        $script = (string) $this->response([
            'js_namespace' => $namespace,
            'internal_traffic_value' => $markerValue,
        ])->getContent();

        self::assertSame($markerValue, $this->browserConfig($script)['value']);
        self::assertSame(1, preg_match('/var namespace = (.*);/', $script, $matches));
        self::assertSame($namespace, json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('</script>', $script);
        self::assertStringNotContainsString("Company\n", $script);
    }

    private function response(array $settings): \Symfony\Component\HttpFoundation\Response
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $settings[$key] ?? $default,
        );

        return (new ScriptController($config, new InternalTrafficSettings($config)))();
    }

    private function browserConfig(string $script): array
    {
        self::assertSame(1, preg_match('/var internalTrafficDefaults = (.*);/', $script, $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
