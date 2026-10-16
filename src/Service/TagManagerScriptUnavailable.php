<?php

declare(strict_types=1);

namespace App\Service;

/** The tag manager script cannot be served; the message names no settings and is safe to show. */
final class TagManagerScriptUnavailable extends \RuntimeException
{
}
