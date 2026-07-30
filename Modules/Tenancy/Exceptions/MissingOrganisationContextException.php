<?php

namespace Modules\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a browser request queries tenant-owned data without an
 * organisation bound to OrganisationContext.
 *
 * This is a programming error, never a user error — it means a route touching
 * tenant data was registered outside the "organisation" middleware. It is a
 * distinct class (rather than a bare RuntimeException) so it can be recognised
 * in tests and given its own alert rule: one of these in production logs is a
 * potential isolation failure and should page someone, not scroll past in a
 * pile of generic runtime errors.
 */
class MissingOrganisationContextException extends RuntimeException {}
