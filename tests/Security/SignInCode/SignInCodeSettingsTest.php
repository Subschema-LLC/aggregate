<?php

declare(strict_types=1);

namespace App\Tests\Security\SignInCode;

use App\Security\SignInCode\SignInCodeSettings;
use App\Service\AggregateConfigLoader;
use App\Service\Email\TransactionalMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class SignInCodeSettingsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['MFA_REQUIRED'], $_SERVER['MFA_REQUIRED'], $_ENV['SIGN_IN_CODES_ENABLED'], $_SERVER['SIGN_IN_CODES_ENABLED']);
        $this->projectDir = sys_get_temp_dir().'/aggregate-sign-in-settings-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testBothStartOffAndTwoStepSignInHidesSignInCodes(): void
    {
        $settings = $this->settings();
        self::assertFalse($settings->isEnabled());
        self::assertFalse($settings->isSecondStepRequired());

        $this->configure(['sign_in_codes_enabled' => true]);
        self::assertTrue($this->settings()->isAvailable());
        $this->configure(['sign_in_codes_enabled' => true, 'mfa_required' => true]);
        self::assertTrue($this->settings()->isSecondStepRequired());
        self::assertFalse($this->settings()->isAvailable(), 'A code alone is not a second factor');
    }

    public function testAnInvalidValueRequiresTheSecondStep(): void
    {
        $this->configure(['mfa_required' => 'perhaps']);

        self::assertTrue($this->settings()->isSecondStepRequired(), 'Fails closed');
    }

    public function testTheEnvironmentWinsAndLocksTheSwitch(): void
    {
        $this->configure(['mfa_required' => true]);
        $_SERVER['MFA_REQUIRED'] = '0';
        $settings = $this->settings();

        self::assertFalse($settings->isSecondStepRequired());
        self::assertTrue($settings->isSecondStepEnvironmentControlled());
        self::assertFalse($settings->saveSecondStep('1'));
        self::assertTrue(Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['mfa_required'], 'YAML is left alone');

        unset($_SERVER['MFA_REQUIRED']);
        self::assertFalse($this->settings()->saveSecondStep(false));
        self::assertFalse(Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['mfa_required']);
    }

    /** @param array<string, mixed> $values */
    private function configure(array $values): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($values));
    }

    private function settings(): SignInCodeSettings
    {
        $mailer = $this->createStub(TransactionalMailer::class);
        $mailer->method('problem')->willReturn(null);

        return new SignInCodeSettings(new AggregateConfigLoader($this->projectDir, 'test'), $mailer);
    }
}
