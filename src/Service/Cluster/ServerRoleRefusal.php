<?php

declare(strict_types=1);

namespace App\Service\Cluster;

/** Thrown when a server is asked to do what its role leaves to another server. */
final class ServerRoleRefusal extends \RuntimeException
{
}
