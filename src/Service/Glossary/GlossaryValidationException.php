<?php

declare(strict_types=1);

namespace App\Service\Glossary;

final class GlossaryValidationException extends \InvalidArgumentException
{
    /** @param array<string, string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode("\n", array_map(
            static fn (string $path, string $message): string => $path.': '.$message,
            array_keys($errors), $errors,
        )));
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
