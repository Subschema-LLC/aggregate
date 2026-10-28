<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Optional at-rest encryption for credential files under config/secrets/
 * (email.yaml, bigquery-service-account.json, bigquery-google-sign-in.json
 * and mcp-oauth.yaml).
 *
 * When AGGREGATE_SECRETS_KEY is set in the environment (at least 32 characters,
 * for example from `openssl rand -hex 32`), writes encrypt the file with
 * XChaCha20-Poly1305 and bind the ciphertext to the relative file path as
 * associated data. Existing plain-text files remain readable until they are
 * next saved. When AGGREGATE_SECRETS_KEY is unset, files are read and written
 * in plain text as before.
 */
final class SecretFileEncryption
{
    public const ENV = 'AGGREGATE_SECRETS_KEY';
    public const PREFIX = 'AGGREGATE-ENC-V1:';
    public const MIN_KEY_LENGTH = 32;
    private const KEY_CONTEXT = 'aggregate-secret-file-v1';

    public static function isEnabled(): bool
    {
        return self::rawKey() !== null;
    }

    public static function isEncrypted(string $contents): bool
    {
        return str_starts_with(ltrim($contents), self::PREFIX);
    }

    /**
     * Encrypts $plaintext when AGGREGATE_SECRETS_KEY is set; returns $plaintext
     * unchanged otherwise.
     */
    public static function encode(#[\SensitiveParameter] string $plaintext, string $associatedData): string
    {
        $key = self::derivedKey();
        if ($key === null) {
            return $plaintext;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $key);

        return self::PREFIX.base64_encode($nonce.$ciphertext)."\n";
    }

    /**
     * Decrypts an encrypted payload using AGGREGATE_SECRETS_KEY, or returns
     * unencrypted content as-is.
     *
     * @throws \RuntimeException when an encrypted file cannot be decrypted
     */
    public static function decode(#[\SensitiveParameter] string $stored, string $associatedData): string
    {
        $trimmed = ltrim($stored);
        if (!str_starts_with($trimmed, self::PREFIX)) {
            // Validate a configured key even when reading an existing plain-text file.
            self::derivedKey();

            return $stored;
        }

        $key = self::derivedKey();
        if ($key === null) {
            throw new \RuntimeException(sprintf('%s is encrypted. Set %s in the environment to read it.', $associatedData, self::ENV));
        }

        $encoded = trim(substr($trimmed, strlen(self::PREFIX)));
        $binary = base64_decode($encoded, true);
        $minLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
        if (!is_string($binary) || strlen($binary) < $minLength) {
            throw new \RuntimeException(sprintf('%s could not be decrypted.', $associatedData));
        }

        $nonce = substr($binary, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($binary, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $associatedData, $nonce, $key);
        if (!is_string($plaintext)) {
            throw new \RuntimeException(sprintf('%s could not be decrypted with %s.', $associatedData, self::ENV));
        }

        return $plaintext;
    }

    private static function derivedKey(): ?string
    {
        $raw = self::rawKey();
        if ($raw === null) {
            return null;
        }
        if (strlen($raw) < self::MIN_KEY_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            throw new \RuntimeException(sprintf('%s must be at least %d characters (for example, openssl rand -hex 32).', self::ENV, self::MIN_KEY_LENGTH));
        }

        return sodium_crypto_generichash(
            $raw,
            self::KEY_CONTEXT,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
        );
    }

    private static function rawKey(): ?string
    {
        foreach ([$_ENV, $_SERVER] as $source) {
            $value = $source[self::ENV] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
