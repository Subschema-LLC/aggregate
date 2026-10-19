<?php

declare(strict_types=1);

namespace App\Tests\Security\SignInCode;

use App\Security\SignInCode\SignInCodes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SignInCodesTest extends TestCase
{
    private MockClock $clock;
    private Session $session;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-19 12:00:00 UTC');
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testACodeWorksOnceAndTheSessionKeepsOnlyItsHash(): void
    {
        $codes = $this->codes();
        $code = (string) $codes->issue($this->session, 'scott');

        self::assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $stored = (string) json_encode($this->session->all());
        self::assertStringNotContainsString($code, $stored, 'Only an HMAC of the code is kept');
        self::assertSame('scott', $codes->pendingUsername($this->session));
        $pending = $codes->pending($this->session);
        self::assertSame('2026-10-19 12:10:00', $pending['expires_at']->format('Y-m-d H:i:s'));
        self::assertSame(SignInCodes::MAX_ATTEMPTS, $pending['attempts_left']);

        self::assertSame('scott', $codes->verify($this->session, substr($code, 0, 3).' '.substr($code, 3)), 'Spaces are ignored');
        self::assertNull($codes->pending($this->session));
        self::assertNull($codes->verify($this->session, $code), 'A code works once');
    }

    public function testWrongCodesUseAttemptsUntilTheCodeIsGone(): void
    {
        $codes = $this->codes();
        $code = (string) $codes->issue($this->session, 'scott');
        $wrong = $code === '000000' ? '000001' : '000000';

        for ($attempt = 1; $attempt < SignInCodes::MAX_ATTEMPTS; ++$attempt) {
            self::assertNull($codes->verify($this->session, $wrong));
            self::assertSame(SignInCodes::MAX_ATTEMPTS - $attempt, $codes->pending($this->session)['attempts_left']);
        }
        self::assertNull($codes->verify($this->session, $wrong));
        self::assertNull($codes->pending($this->session), 'The last wrong code ends the sign-in');
        self::assertNull($codes->verify($this->session, $code), 'Even the right code no longer works');
    }

    public function testACodeExpiresAndANewOneReplacesIt(): void
    {
        $codes = $this->codes();
        $first = (string) $codes->issue($this->session, 'scott');
        $second = (string) $codes->issue($this->session, 'scott');
        if ($first !== $second) {
            self::assertNull($codes->verify($this->session, $first), 'Asking again replaces the code');
        }
        $third = (string) $codes->issue($this->session, 'scott');

        $this->clock->sleep(SignInCodes::LIFETIME);
        self::assertNull($codes->pending($this->session));
        self::assertNull($codes->verify($this->session, $third));
    }

    public function testADecoyLooksPendingButNeverMatches(): void
    {
        $codes = $this->codes();
        self::assertNull($codes->issue($this->session, null));

        self::assertNotNull($codes->pending($this->session));
        self::assertNull($codes->pendingUsername($this->session));
        foreach (['000000', '123456', '999999', ''] as $guess) {
            self::assertNull($codes->verify($this->session, $guess));
        }
    }

    public function testACodeIsBoundToTheSecretAndTheAccount(): void
    {
        $code = (string) $this->codes('secret-one')->issue($this->session, 'scott');
        self::assertNull($this->codes('secret-two')->verify($this->session, $code));

        $code = (string) $this->codes()->issue($this->session, 'scott');
        $pending = $this->session->get(SignInCodes::SESSION_KEY);
        $this->session->set(SignInCodes::SESSION_KEY, ['username' => 'admin'] + $pending);
        self::assertNull($this->codes()->verify($this->session, $code), 'Changing the account in the session invalidates the code');
    }

    public function testATamperedSessionEntryIsIgnored(): void
    {
        $this->session->set(SignInCodes::SESSION_KEY, ['username' => 'scott', 'hash' => 'x', 'issued_at' => 'now', 'attempts' => 0]);

        self::assertNull($this->codes()->pending($this->session));
        self::assertNull($this->codes()->verify($this->session, '123456'));
    }

    private function codes(string $secret = 'test-secret'): SignInCodes
    {
        return new SignInCodes($secret, $this->clock);
    }
}
