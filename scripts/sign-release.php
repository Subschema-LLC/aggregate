#!/usr/bin/env php
<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Sign the exact manifest bytes. Keep private keys outside the repository.

function createKeyFile(string $path, string $contents, int $mode): void
{
    $previousMask = umask(0077);
    try {
        $handle = @fopen($path, 'xb');
    } finally {
        umask($previousMask);
    }
    if ($handle === false) {
        throw new RuntimeException('Key destination already exists or cannot be created: '.$path);
    }
    try {
        if (!chmod($path, $mode) || fwrite($handle, $contents) !== strlen($contents)) {
            throw new RuntimeException('Cannot write key file: '.$path);
        }
    } catch (Throwable $exception) {
        @unlink($path);
        throw $exception;
    } finally {
        fclose($handle);
    }
}

try {
    if (!extension_loaded('sodium')) {
        throw new RuntimeException('The PHP sodium extension is required.');
    }
    if (($argv[1] ?? '') === '--generate-keypair') {
        if ($argc !== 4) {
            throw new RuntimeException('Usage: php scripts/sign-release.php --generate-keypair PRIVATE_PATH PUBLIC_PATH');
        }
        if (file_exists($argv[2]) || is_link($argv[2]) || file_exists($argv[3]) || is_link($argv[3]) || $argv[2] === $argv[3]) {
            throw new RuntimeException('Use two new file paths; existing keys will never be overwritten.');
        }
        $keypair = sodium_crypto_sign_keypair();
        $private = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);
        createKeyFile($argv[2], base64_encode($private)."\n", 0600);
        try {
            createKeyFile($argv[3], base64_encode($public)."\n", 0644);
        } catch (Throwable $exception) {
            @unlink($argv[2]);
            throw $exception;
        }
        sodium_memzero($private);
        sodium_memzero($keypair);
        echo "Created private signing key (0600): ".$argv[2]."\nCreated public verification key: ".$argv[3]."\n";
        exit(0);
    }
    if ($argc < 2 || $argc > 3) {
        throw new RuntimeException('Usage: RELEASE_SIGNING_PRIVATE_KEY=<base64-key> php scripts/sign-release.php MANIFEST [PUBLIC_KEY_PATH]');
    }
    $manifestPath = $argv[1];
    $publicKeyPath = $argv[2] ?? dirname(__DIR__).'/config/release-signing.pub';
    $manifest = @file_get_contents($manifestPath);
    if (!is_string($manifest) || strlen($manifest) > 65536) {
        throw new RuntimeException('Cannot read release manifest (maximum 64 KiB).');
    }
    $metadata = json_decode($manifest, true, 32, JSON_THROW_ON_ERROR);
    if (($metadata['schema'] ?? null) !== 1 || !isset($metadata['package']['sha256'])) {
        throw new RuntimeException('Expected a schema 1 release manifest with package metadata.');
    }
    $encodedPrivate = getenv('RELEASE_SIGNING_PRIVATE_KEY');
    $private = is_string($encodedPrivate) ? base64_decode(trim($encodedPrivate), true) : false;
    if (!is_string($private) || strlen($private) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('RELEASE_SIGNING_PRIVATE_KEY must contain a base64-encoded 64-byte Ed25519 secret key.');
    }
    $encodedPublic = @file_get_contents($publicKeyPath);
    $public = is_string($encodedPublic) ? base64_decode(trim($encodedPublic), true) : false;
    if (!is_string($public) || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new RuntimeException('Install the matching base64 public key at '.$publicKeyPath.' before signing releases.');
    }
    if (!hash_equals($public, sodium_crypto_sign_publickey_from_secretkey($private))) {
        throw new RuntimeException('Signing key does not match the public key shipped to installations.');
    }
    $signature = sodium_crypto_sign_detached($manifest, $private);
    sodium_memzero($private);
    if (!sodium_crypto_sign_verify_detached($signature, $manifest, $public)) {
        throw new RuntimeException('Generated signature failed verification.');
    }
    $destination = $manifestPath.'.sig';
    createKeyFile($destination, base64_encode($signature)."\n", 0644);
    echo 'Signed manifest: '.$destination."\n";
} catch (Throwable $exception) {
    // Never include key material in errors or CI logs.
    fwrite(STDERR, 'Release signing failed: '.$exception->getMessage()."\n");
    exit(1);
}
