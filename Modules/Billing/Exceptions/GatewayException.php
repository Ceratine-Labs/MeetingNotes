<?php

namespace Modules\Billing\Exceptions;

use RuntimeException;

/**
 * A payment provider call failed — network error, non-2xx response, or a
 * well-formed response that did not contain what we asked for.
 *
 * Deliberately one exception type for all three. From a caller's point of view
 * the recovery is identical: do not mark anything paid, tell the customer the
 * payment could not be started, and log it for follow-up. Splitting this into
 * TransportException / ApiException / MalformedResponseException would multiply
 * catch blocks without changing a single decision.
 *
 * Messages here reach a log, not the customer. Never interpolate a secret key
 * into one; do include the provider's own error text, which is usually the only
 * clue as to what was rejected.
 */
class GatewayException extends RuntimeException {}
