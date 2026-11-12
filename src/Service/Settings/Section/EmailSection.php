<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Repository\UserRepository;
use App\Security\SignInCode\SignInCodeSettings;
use App\Service\AggregateConfigLoader;
use App\Service\Email\EmailSecrets;
use App\Service\Email\EmailSettings;
use App\Service\Email\TransactionalMailer;
use App\Service\Settings\AbstractSection;
use App\Service\Settings\SettingsWithSecrets;

/**
 * Email, as the Email page saves it: the service and sender, sign-in codes,
 * two-step sign-in, and each service's API key or password as a secret.
 * Two-step sign-in is turned on only when email can be sent and an
 * administrator has an email address, so nobody is locked out.
 */
final class EmailSection extends AbstractSection implements SettingsWithSecrets
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly EmailSettings $settings,
        private readonly EmailSecrets $secrets,
        private readonly TransactionalMailer $mailer,
        private readonly SignInCodeSettings $signInCodes,
        private readonly UserRepository $users,
    ) {
    }

    public function name(): string
    {
        return 'email';
    }

    public function page(): string
    {
        return 'Administration > Email';
    }

    public function keys(): array
    {
        return [...array_keys($this->settings->defaults()), SignInCodeSettings::KEY, SignInCodeSettings::KEY_MFA];
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return [
            ...$this->settings->toArray(),
            SignInCodeSettings::KEY => $this->signInCodes->isEnabled(),
            SignInCodeSettings::KEY_MFA => $this->signInCodes->isSecondStepRequired(),
        ];
    }

    public function environmentOverrides(?string $target = null): array
    {
        $this->assertTarget($target);

        return [
            ...array_keys(array_filter($this->settings->environmentOverrides())),
            ...($this->signInCodes->isEnvironmentControlled() ? [SignInCodeSettings::KEY] : []),
            ...($this->signInCodes->isSecondStepEnvironmentControlled() ? [SignInCodeSettings::KEY_MFA] : []),
        ];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $codes = array_key_exists(SignInCodeSettings::KEY, $changes) ? $changes[SignInCodeSettings::KEY] : null;
        $mfa = array_key_exists(SignInCodeSettings::KEY_MFA, $changes) ? $changes[SignInCodeSettings::KEY_MFA] : null;
        $email = array_diff_key($changes, [SignInCodeSettings::KEY => true, SignInCodeSettings::KEY_MFA => true]);
        $overridden = array_values(array_intersect(array_keys($changes), $this->environmentOverrides()));

        try {
            $current = $this->settings->toArray();
        } catch (\Throwable) {
            $current = $this->settings->validate([]);
        }
        $candidate = $this->settings->validate([...$current, ...$email]);
        if ($codes !== null) {
            SignInCodeSettings::validate($codes);
        }
        $wantsMfa = $mfa !== null ? SignInCodeSettings::validate($mfa, SignInCodeSettings::KEY_MFA) : null;
        if ($check) {
            return [self::done(true, '')];
        }

        $saved = $email !== [] ? $this->settings->save($email) : $candidate;
        $codesOn = $codes !== null ? $this->signInCodes->save($codes) : $this->signInCodes->isEnabled();
        $notes = array_values(array_filter([self::overriddenNote($overridden), 'Email settings were saved.']));
        $problem = $this->mailer->problem($saved);
        $mfaOn = $this->signInCodes->isSecondStepRequired();
        if ($wantsMfa !== null && $wantsMfa !== $mfaOn && !$this->signInCodes->isSecondStepEnvironmentControlled()) {
            if (!$wantsMfa) {
                $mfaOn = $this->signInCodes->saveSecondStep(false);
                $notes[] = 'Two-step sign-in is off: a password signs in on its own again.';
            } elseif ($problem !== null) {
                $notes[] = 'Two-step sign-in was not turned on: email must work first. '.$problem;
            } elseif (!$this->anAdministratorHasEmail()) {
                $notes[] = 'Two-step sign-in was not turned on: no administrator has an email address, so nobody could sign in again.';
            } else {
                $mfaOn = $this->signInCodes->saveSecondStep(true);
                $without = $this->users->usernamesWithoutEmail();
                $notes[] = 'Two-step sign-in is now required. Sessions sign in again on their next page.'
                    .($without !== [] ? ' These users have no email address and cannot sign in until they get one: '.implode(', ', $without).'.' : '');
            }
        }
        if (EmailSettings::isEnabled($saved) && $problem !== null) {
            $notes[] = 'Email is on, but nothing can be sent yet: '.$problem;
        } elseif ($codesOn && $problem !== null && !$mfaOn) {
            $notes[] = 'Sign-in codes are on, but the sign-in page will not offer them until email can be sent: '.$problem;
        }
        if ($mfaOn && $problem !== null) {
            $notes[] = 'Two-step sign-in is required, but email cannot be sent, so nobody can finish signing in: '.$problem;
        }

        return $notes;
    }

    public function secrets(): array
    {
        $secrets = [];
        foreach ($this->settings->providers()->all() as $provider) {
            $secrets[$provider->credential()->key] = $provider->credential()->label.' ('.$provider->label().')';
        }

        return $secrets;
    }

    public function secretsSet(?string $target = null): array
    {
        $this->assertTarget($target);
        $set = [];
        foreach ($this->settings->providers()->all() as $provider) {
            $set[$provider->credential()->key] = $this->secrets->get($provider->credential()) !== null;
        }

        return $set;
    }

    public function setSecret(string $name, #[\SensitiveParameter] ?string $value, ?string $target = null, bool $check = false): string
    {
        $this->assertTarget($target);
        foreach ($this->settings->providers()->all() as $provider) {
            $credential = $provider->credential();
            if ($credential->key !== $name) {
                continue;
            }
            if ($this->secrets->isEnvironmentControlled($credential)) {
                throw new \InvalidArgumentException(sprintf('The %s is set by %s and cannot be changed here.', strtolower($credential->label), $credential->environmentVariable));
            }
            if ($value !== null) {
                $value = trim($value);
                if ($value === '' || strlen($value) > EmailSecrets::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    throw new \InvalidArgumentException(sprintf('The %s must be one line of at most %d characters.', strtolower($credential->label), EmailSecrets::MAX_LENGTH));
                }
            }
            if (!$check) {
                $value === null ? $this->secrets->remove($credential) : $this->secrets->set($credential, $value);
            }

            return self::done($check, sprintf('The %s was %s.', strtolower($credential->label), $value === null ? 'removed' : 'saved'));
        }

        throw new \InvalidArgumentException('The email secrets are: '.implode(', ', array_keys($this->secrets())).'.');
    }

    private function anAdministratorHasEmail(): bool
    {
        foreach ($this->users->findAll() as $user) {
            if ($user->isAdmin() && $user->getEmail() !== null && $user->getEmail() !== '') {
                return true;
            }
        }

        return false;
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
