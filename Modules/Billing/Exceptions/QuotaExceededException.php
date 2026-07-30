<?php

namespace Modules\Billing\Exceptions;

use RuntimeException;
use Modules\Billing\Support\QuotaStatus;

/**
 * Thrown when an organisation tries to generate minutes with no allowance left.
 *
 * Carries the QuotaStatus so the catching layer can render a useful message
 * ("you have used all 3 of your monthly generations") and a working upgrade
 * link, instead of a bare "quota exceeded" that leaves the customer guessing at
 * what their limit is or how to raise it.
 *
 * This is an expected condition, not a fault: it is the free tier working as
 * designed. It should never page anyone, and the handler renders it as a
 * friendly wall, not a 500.
 */
class QuotaExceededException extends RuntimeException
{
    public function __construct(public readonly QuotaStatus $status)
    {
        parent::__construct(sprintf(
            'Generation quota exhausted: %d of %s used in the current period.',
            $status->used,
            $status->limit === null ? 'unlimited' : (string) $status->limit
        ));
    }
}
