<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Contracts\PaymentGateway;
use Modules\Billing\Services\WebhookProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Paystack webhooks.
 *
 * Four properties this endpoint must have, and how each is achieved:
 *
 *   **Unauthenticated but not unauthorised.** There is no session and no user —
 *   Paystack's servers are the caller. Authenticity comes from the HMAC-SHA512
 *   signature over the raw body, checked before the payload is trusted for
 *   anything. An unsigned or mis-signed request is refused.
 *
 *   **CSRF-exempt.** Applied in the route file, since there is no browser session
 *   and therefore no CSRF token to present.
 *
 *   **Fast.** Paystack times out and retries. Recording and processing here is a
 *   handful of queries, so it stays synchronous — but if handling ever grows,
 *   this is the place to dispatch a job and return immediately.
 *
 *   **Idempotent.** Retries are normal, not exceptional. WebhookProcessor::record()
 *   relies on a unique database index rather than a check-then-write, so
 *   concurrent duplicate deliveries cannot both apply.
 *
 * It always returns 200 once the signature is valid, even when handling fails.
 * A non-2xx makes Paystack retry, and for a payload that will fail deterministically
 * (a malformed event, a plan we cannot match) retrying forever achieves nothing —
 * the event row keeps the error and the admin UI can replay it deliberately.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly WebhookProcessor $processor,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        // getContent() gives the exact bytes received. Using $request->all() and
        // re-encoding would change whitespace and key order, and the signature
        // would never match — a subtle way to make every webhook fail.
        $rawBody = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (! $this->gateway->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('Rejected a Paystack webhook with an invalid signature', [
                'ip' => $request->ip(),
                'has_signature' => $signature !== null,
            ]);

            // 401, not 400: this is an authenticity failure. Paystack will retry,
            // which is correct if the cause was a key rotated mid-flight.
            return response()->json(['message' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            // Signed but unparseable. Retrying cannot fix malformed JSON, so
            // acknowledge and stop.
            Log::error('Paystack webhook passed signature check but the body was not JSON.');

            return response()->json(['message' => 'Acknowledged.']);
        }

        $event = $this->processor->record($payload);

        // Null = already recorded. Acknowledge silently; the first delivery
        // handled it.
        if ($event === null) {
            return response()->json(['message' => 'Duplicate — already processed.']);
        }

        // Catches its own failures internally and records them on the event row,
        // so a handling bug cannot turn into an infinite retry loop.
        $this->processor->process($event);

        return response()->json(['message' => 'Processed.']);
    }
}
