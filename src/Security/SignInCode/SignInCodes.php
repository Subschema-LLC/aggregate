<?php

declare(strict_types=1);

namespace App\Security\SignInCode;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The one pending sign-in code of a browser, kept in its server-side session
 * rather than in YAML or a table: a six-digit code needs an attempt count,
 * and the session already holds sign-in state for this browser. Only an
 * HMAC of the code is kept, keyed with the application secret, alongside the
 * account, when it was issued and how many attempts remain.
 *
 * A code lasts LIFETIME seconds, allows MAX_ATTEMPTS tries and works once,
 * in the browser that asked for it. Asking again replaces it. A request for
 * an account that does not exist leaves a decoy that never matches, so the
 * page behaves the same either way.
 *
 * A code is one of two kinds, bound into its HMAC: KIND_SIGN_IN signs in on
 * its own (sign-in codes), KIND_SECOND_STEP finishes a sign-in whose password
 * was already accepted (two-step sign-in).
 */
class SignInCodes
{
    public const SESSION_KEY = '_aggregate_sign_in_code';
    public const LIFETIME = 600;
    public const MAX_ATTEMPTS = 5;
    public const DIGITS = 6;
    public const KIND_SIGN_IN = 'sign_in';
    public const KIND_SECOND_STEP = 'second_step';
    private const KINDS = [self::KIND_SIGN_IN, self::KIND_SECOND_STEP];

    private readonly ClockInterface $clock;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private readonly string $secret,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? Clock::get();
    }

    /**
     * Starts a sign-in for an account and returns the code to send it, or, for
     * no account, leaves a decoy and returns null.
     */
    public function issue(SessionInterface $session, ?string $username, string $kind = self::KIND_SIGN_IN): ?string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown kind of sign-in code.');
        }
        $now = $this->clock->now()->getTimestamp();
        $code = null;
        $hash = bin2hex(random_bytes(32));
        if ($username !== null) {
            $code = str_pad((string) random_int(0, 10 ** self::DIGITS - 1), self::DIGITS, '0', STR_PAD_LEFT);
            $hash = $this->hash($username, $kind, $now, $code);
        }
        $session->set(self::SESSION_KEY, [
            'username' => $username,
            'kind' => $kind,
            'hash' => $hash,
            'issued_at' => $now,
            'attempts' => 0,
        ]);

        return $code;
    }

    /**
     * The pending sign-in while it can still be used: when it expires and how
     * many attempts are left. A decoy looks the same.
     *
     * @return array{expires_at: \DateTimeImmutable, attempts_left: int, kind: string}|null
     */
    public function pending(SessionInterface $session): ?array
    {
        $pending = $this->usable($session);

        return $pending === null ? null : [
            'expires_at' => (new \DateTimeImmutable('@'.($pending['issued_at'] + self::LIFETIME)))->setTimezone(new \DateTimeZone('UTC')),
            'attempts_left' => self::MAX_ATTEMPTS - $pending['attempts'],
            'kind' => $pending['kind'],
        ];
    }

    /** The kind of the pending code (KIND_*), or null when none is pending. */
    public function pendingKind(SessionInterface $session): ?string
    {
        return $this->usable($session)['kind'] ?? null;
    }

    /** The account a pending sign-in is for, or null for none or a decoy. */
    public function pendingUsername(SessionInterface $session): ?string
    {
        return $this->usable($session)['username'] ?? null;
    }

    /**
     * Checks a typed code. Every check uses an attempt; a right code ends the
     * pending sign-in and returns its account. The comparison takes the same
     * time for a decoy, a wrong code and a right one.
     */
    public function verify(SessionInterface $session, #[\SensitiveParameter] string $typed): ?string
    {
        $pending = $this->usable($session);
        if ($pending === null) {
            return null;
        }
        ++$pending['attempts'];
        $session->set(self::SESSION_KEY, $pending);

        $typed = self::normalize($typed);
        $expected = $this->hash((string) $pending['username'], $pending['kind'], $pending['issued_at'], $typed);
        $matches = hash_equals($pending['hash'], $expected) && $pending['username'] !== null && preg_match('/^[0-9]{'.self::DIGITS.'}$/D', $typed) === 1;
        if ($matches || $pending['attempts'] >= self::MAX_ATTEMPTS) {
            $session->remove(self::SESSION_KEY);
        }

        return $matches ? $pending['username'] : null;
    }

    public function clear(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    /** What someone typed, without the spaces and dashes a code is often copied with. */
    public static function normalize(string $typed): string
    {
        return (string) preg_replace('/[\s\x{2010}-\x{2015}-]+/u', '', trim($typed));
    }

    /** @return array{username: ?string, kind: string, hash: string, issued_at: int, attempts: int}|null */
    private function usable(SessionInterface $session): ?array
    {
        $pending = $session->get(self::SESSION_KEY);
        if (!is_array($pending) || !is_string($pending['hash'] ?? null) || !is_int($pending['issued_at'] ?? null)
            || !is_int($pending['attempts'] ?? null) || !array_key_exists('username', $pending)
            || !in_array($pending['kind'] ?? null, self::KINDS, true)
            || !($pending['username'] === null || is_string($pending['username']))) {
            return null;
        }
        $now = $this->clock->now()->getTimestamp();
        if ($pending['attempts'] >= self::MAX_ATTEMPTS || $now >= $pending['issued_at'] + self::LIFETIME || $now < $pending['issued_at']) {
            $session->remove(self::SESSION_KEY);

            return null;
        }

        return $pending;
    }

    private function hash(string $username, string $kind, int $issuedAt, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', implode("\0", ['aggregate-sign-in-code', $kind, $username, (string) $issuedAt, $code]), $this->secret);
    }
}
