<?php

declare(strict_types=1);

namespace App\Entities\Incident;

/** A UTC hour boundary written as 2026-11-17T09:00:00Z, as alerts store and send it. */
final class UtcHour
{
    public const LENGTH = 20;

    public static function format(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:00:00\Z');
    }

    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:00:00Z$/D', $value) !== 1) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
