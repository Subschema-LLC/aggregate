<?php

declare(strict_types=1);

namespace App\Entities\Incident;

/** The saved incident states and when the alerts were last checked. */
interface IncidentStates
{
    /**
     * Empty values when nothing can be read: the status page still shows.
     *
     * @return array{
     *     last_check: ?\DateTimeImmutable,
     *     last_window_end: ?string,
     *     states: array<string, ?string>
     * } states by IncidentState::subject()
     */
    public function snapshot(): array;
}
