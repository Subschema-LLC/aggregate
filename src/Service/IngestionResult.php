<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Response;

/** What /api/receive answers: a JSON body and an HTTP status. */
final class IngestionResult
{
    /** @param array<string, mixed> $body */
    private function __construct(public readonly array $body, public readonly int $status)
    {
    }

    /** Valid enough to answer, but nothing is collected: disabled, excluded, a dropped bot or an unusable scope. */
    public static function ignored(): self
    {
        return new self(['status' => 'ignored'], Response::HTTP_ACCEPTED);
    }

    public static function error(string $message, int $status): self
    {
        return new self(['error' => $message], $status);
    }

    public static function recorded(string $mode, bool $goalWasRejected): self
    {
        $body = $mode === 'anonymous'
            ? ['status' => 'recorded', 'mode' => 'anonymous']
            : ['status' => 'accepted', 'mode' => 'enhanced'];
        if ($goalWasRejected) {
            $body['warnings'] = ['goal_not_allowed'];
        }

        return new self($body, Response::HTTP_ACCEPTED);
    }
}
