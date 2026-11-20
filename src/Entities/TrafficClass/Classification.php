<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/** The class a request was given, how sure that is, and why. */
final class Classification
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly TrafficClass $class,
        public readonly Confidence $confidence,
        public readonly ?string $operator = null,
        public readonly array $reasons = [],
    ) {
    }

    /**
     * @return array{class: string, label: string, confidence: string, operator: ?string,
     *     reasons: list<string>}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class->value,
            'label' => $this->class->label(),
            'confidence' => $this->confidence->value,
            'operator' => $this->operator,
            'reasons' => $this->reasons,
        ];
    }
}
