<?php

declare(strict_types=1);

namespace App\Tests\Features\Destinations\SaveSigningSecret;

use App\Features\Destinations\SaveSigningSecret\SaveSigningSecret;
use App\Tests\Features\Destinations\SaveDestination\FakeSecrets;
use PHPUnit\Framework\TestCase;

final class SaveSigningSecretTest extends TestCase
{
    public function testOnlyTheSecretTheEnvironmentSetsIsRefused(): void
    {
        $secrets = new FakeSecrets();
        $secrets->environment[''] = 'shared-from-env';
        $feature = new SaveSigningSecret($secrets);

        $feature->set('own', 'hub');
        self::assertSame(['hub' => 'own'], $secrets->saved, 'An own secret wins over the shared one.');

        $this->expectExceptionMessage('The webhook signing secret is set in the environment');
        $feature->generate(null);
    }

    public function testAnOwnSecretFromTheEnvironmentCannotBeRemovedHere(): void
    {
        $secrets = new FakeSecrets();
        $secrets->environment['hub'] = 'own-from-env';

        $this->expectExceptionMessage('set by ALERTS_WEBHOOK_SECRET_HUB in the environment');
        (new SaveSigningSecret($secrets))->remove('hub');
    }
}
