<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/**
 * How sure a classification is:
 *
 * - verified: the address is in the operator's own published range list;
 * - declared: the User-Agent says what it is, which anyone can copy;
 * - inferred: weighted signals, such as automation the browser reports or a
 *   cloud provider's network; also every human classification, since
 *   nothing proves a visitor is a person.
 */
enum Confidence: string
{
    case Verified = 'verified';
    case Declared = 'declared';
    case Inferred = 'inferred';
}
