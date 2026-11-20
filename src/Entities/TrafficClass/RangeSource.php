<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/**
 * A published list of IP ranges: an AI or search operator's list for its
 * bots, or a cloud provider's list for its network. A bot list may name the
 * class of everything it holds; when it covers several bots, as Anthropic's
 * one list does, the class comes from the User-Agent.
 */
final class RangeSource
{
    public function __construct(
        public readonly string $id,
        public readonly string $operator,
        public readonly bool $cloud = false,
        public readonly ?TrafficClass $class = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $id) !== 1) {
            throw new \InvalidArgumentException(
                'A range source id uses lower-case letters, digits and hyphens.',
            );
        }
        if (preg_match('/^[a-z0-9-]{1,40}$/', $operator) !== 1) {
            throw new \InvalidArgumentException('A range source operator must be a short code.');
        }
        if ($cloud && $class !== null) {
            throw new \InvalidArgumentException('A cloud network list names no traffic class.');
        }
    }
}
