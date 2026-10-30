<?php

declare(strict_types=1);

namespace App\Service\ForensicDatabase;

/**
 * Replaces browser-generated identifiers with keyed hashes for one forensic
 * copy. The key is 32 random bytes made when the copy starts, held only in
 * this object and wiped when the copy ends (forget()); it is never written to
 * disk, logged or stored. The same identifier gets the same hash within one
 * copy, so one browser's events can be followed through it, but the hash
 * cannot be computed again afterwards, by anyone, and the next copy hashes
 * every identifier differently. A hash therefore cannot be traced back to the
 * identifier a browser holds, or matched across copies.
 *
 * Each identifier kind has its own domain, so a visitor ID and a session ID
 * that happen to be equal get different hashes.
 */
final class ForensicPseudonyms
{
    private const KEY_BYTES = 32;

    private ?string $key;

    public function __construct()
    {
        $this->key = random_bytes(self::KEY_BYTES);
    }

    /**
     * The hash of one identifier, as 64 hexadecimal characters; null for a
     * missing or empty identifier.
     *
     * @throws \LogicException after forget()
     */
    public function hash(string $domain, mixed $identifier): ?string
    {
        if ($this->key === null) {
            throw new \LogicException('The key of this forensic copy is gone; make a new ForensicPseudonyms for the next copy.');
        }
        if (is_resource($identifier)) {
            $identifier = stream_get_contents($identifier);
        }
        if ($identifier === null || $identifier === false || !is_scalar($identifier) || (string) $identifier === '') {
            return null;
        }

        return hash_hmac('sha256', $domain."\0".(string) $identifier, $this->key);
    }

    /** Wipes the key; hashes cannot be made again after this. */
    public function forget(): void
    {
        if ($this->key !== null && function_exists('sodium_memzero')) {
            // Overwrites the key's bytes in memory, then sets it to null.
            sodium_memzero($this->key);
        }
        $this->key = null;
    }

    public function __destruct()
    {
        $this->forget();
    }

    /** The key must never leave this object, not even in a debug dump. */
    public function __debugInfo(): array
    {
        return ['key' => $this->key === null ? 'forgotten' : 'held in memory'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('A forensic copy’s key cannot be serialized.');
    }

    public function __clone()
    {
        throw new \LogicException('A forensic copy’s key cannot be copied.');
    }
}
