<?php

declare(strict_types=1);

namespace App\Features\Alerts\DeleteDestination;

use App\Entities\Destination\Destinations;
use App\Entities\Destination\SigningSecrets;

/** Deletes a destination no rule uses, and the signing secret of its own. */
final class DeleteDestination
{
    public function __construct(
        private readonly Destinations $destinations,
        private readonly SigningSecrets $secrets,
    ) {
    }

    public function delete(string $id): void
    {
        $id = trim($id);
        $this->destinations->delete($id);
        $this->secrets->remove($id);
    }
}
