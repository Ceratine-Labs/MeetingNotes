<?php

namespace Modules\Billing\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Contracts\PaymentGateway;
use Modules\Billing\Exceptions\GatewayException;
use Modules\Billing\Support\GatewayPlan;
use Modules\Billing\Support\GatewayTransaction;

/**
 * Paystack implementation of PaymentGateway.
 *
 * Everything Paystack-shaped is confined to this class: its JSON envelope, its
 * field names, its HMAC scheme. Callers get our own GatewayTransaction /
 * GatewayPlan objects back, so a change at Paystack's end breaks here and
 * nowhere else.
 *
 * Amounts. Paystack works in the currency's **subunit** — cents for ZAR, kobo
 * for NGN — which is the same unit our database uses. So amounts pass straight
 * through with no conversion, and there is deliberately no `* 100` anywhere in
 * this file. If you find yourself adding one, the bug is upstream.
 *
 * Secrets. The secret key is read from config (env-backed) on each call and is
 * never logged. The redaction in log context below is not decoration — Laravel's
 * HTTP client exception messages can otherwise carry the request headers.
 *
 * API reference: https://paystack.com/docs/api/
 */
class PaystackGateway implements PaymentGateway
{
    public function initialiseTransaction(
        string $email,
        int $amountCents,
        string $reference,
        string $callbackUrl,
        ?string $planCode = null,
        array $metadata = [],
    ): GatewayTransaction {
        $payload = [
            'email' => $email,
            'amount' => $amountCents,
            'currency' => config('billing.currency'),
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ];

        // Passing `plan` turns a one-off charge into a recurring subscription.
        // When present, Paystack ignores `amount` and bills the plan's own price
        // — which is why PlanService keeps the two in step, and why a plan whose
        // Paystack price has drifted from ours is a real (silent) billing bug.
        if ($planCode !== null) {
            $payload['plan'] = $planCode;
        }

        $data = $this->post('/transaction/initialize', $payload);

        // No authorization_url means there is nowhere to send the customer.
        // Failing loudly beats redirecting to null and showing a blank page.
        if (empty($data['authorization_url'])) {
            throw new GatewayException(
                "Paystack accepted the transaction for reference [{$reference}] but returned no authorization_url."
            );
        }

        return new GatewayTransaction(
            // NOT successful — the customer has not paid yet, they have merely
            // been given somewhere to pay. Only verifyTransaction() may set this
            // true.
            successful: false,
            reference: $reference,
            providerReference: $data['reference'] ?? $reference,
            amountCents: $amountCents,
            authorizationUrl: $data['authorization_url'],
            raw: $data,
        );
    }

    public function verifyTransaction(string $reference): GatewayTransaction
    {
        $data = $this->get('/transaction/verify/'.urlencode($reference));

        // Paystack's transaction status: 'success' | 'failed' | 'abandoned' |
        // 'pending'. Only the exact string 'success' counts — a truthy check
        // would treat 'failed' as paid.
        $successful = ($data['status'] ?? null) === 'success';

        return new GatewayTransaction(
            successful: $successful,
            reference: $data['reference'] ?? $reference,
            providerReference: $data['reference'] ?? null,
            // Cast: Paystack returns this as a JSON number, and the rest of the
            // system requires an int for money.
            amountCents: (int) ($data['amount'] ?? 0),
            authorizationUrl: null,
            customerCode: $data['customer']['customer_code'] ?? null,
            // Present only when the charge created a subscription (i.e. a `plan`
            // was supplied at initialise).
            subscriptionCode: $data['plan_object']['subscription_code']
                ?? $data['subscription_code']
                ?? null,
            emailToken: $data['plan_object']['email_token'] ?? null,
            channel: $data['channel'] ?? null,
            // Last four digits only. Storing or logging more of a card number is
            // a PCI problem we have no reason to take on.
            cardLast4: $data['authorization']['last4'] ?? null,
            cardBrand: $data['authorization']['brand'] ?? null,
            failureReason: $successful ? null : ($data['gateway_response'] ?? 'Payment was not completed.'),
            raw: $data,
        );
    }

    public function createPlan(string $name, int $amountCents, string $interval, string $currency): GatewayPlan
    {
        $data = $this->post('/plan', [
            'name' => $name,
            'amount' => $amountCents,
            // Paystack's vocabulary matches ours for the intervals we use
            // ('monthly', 'annually'), so no mapping table is needed. It would
            // be if we ever offered weekly or quarterly.
            'interval' => $interval,
            'currency' => $currency,
        ]);

        if (empty($data['plan_code'])) {
            throw new GatewayException("Paystack created plan [{$name}] but returned no plan_code.");
        }

        return new GatewayPlan(
            code: $data['plan_code'],
            name: $data['name'] ?? $name,
            amountCents: (int) ($data['amount'] ?? $amountCents),
            interval: $data['interval'] ?? $interval,
            raw: $data,
        );
    }

    public function disableSubscription(string $subscriptionCode, string $emailToken): void
    {
        $this->post('/subscription/disable', [
            'code' => $subscriptionCode,
            'token' => $emailToken,
        ]);
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        $secret = $this->secretKey();

        // No key configured means we cannot verify anything, so nothing can be
        // trusted. Returning false (rather than throwing, or worse, true) makes
        // an unconfigured deployment reject webhooks instead of accepting
        // forged ones.
        if ($secret === null) {
            Log::warning('Paystack webhook received but no secret key is configured — rejecting.');

            return false;
        }

        // Paystack signs the raw request body with HMAC-SHA512 using the secret
        // key. The body must be the exact bytes received: decoding the JSON and
        // re-encoding it changes whitespace and key order, and the digest will
        // never match.
        $expected = hash_hmac('sha512', $rawBody, $secret);

        // Timing-safe. A plain === leaks, through response timing, how many
        // leading bytes of a forged signature were correct — enough to forge one
        // byte at a time.
        return hash_equals($expected, $signature);
    }

    /**
     * POST to the Paystack API and return the `data` envelope.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    private function post(string $path, array $payload): array
    {
        return $this->send('post', $path, $payload);
    }

    /**
     * GET from the Paystack API and return the `data` envelope.
     *
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    private function get(string $path): array
    {
        return $this->send('get', $path);
    }

    /**
     * Issue the request and unwrap Paystack's response envelope.
     *
     * Every Paystack response is `{status: bool, message: string, data: {...}}`,
     * where `status` is the API's own success flag and is independent of the HTTP
     * status code — a 200 with `status: false` is a rejected request. Both are
     * checked.
     *
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    private function send(string $method, string $path, array $payload = []): array
    {
        $secret = $this->secretKey();

        if ($secret === null) {
            throw new GatewayException(
                'PAYSTACK_SECRET_KEY is not set. Set it in .env, or leave BILLING_ENABLED=false '
                .'to run on the free plan only.'
            );
        }

        $url = rtrim((string) config('billing.paystack.base_url'), '/').$path;

        try {
            /** @var Response $response */
            $response = Http::withToken($secret)
                ->acceptJson()
                ->timeout((int) config('billing.paystack.timeout'))
                // One retry, 500 ms apart. Enough to ride out a dropped
                // connection; deliberately not more, because the customer is
                // sitting in front of a checkout waiting for this.
                ->retry(2, 500, throw: false)
                ->{$method}($url, $payload);
        } catch (ConnectionException $e) {
            // Context, not the message, carries the path — and no headers, so
            // the secret key cannot end up in the log.
            Log::error('Paystack connection failed', ['path' => $path, 'error' => $e->getMessage()]);

            throw new GatewayException("Could not reach Paystack ({$path}): {$e->getMessage()}", previous: $e);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new GatewayException(
                "Paystack returned a non-JSON response for [{$path}] with HTTP {$response->status()}."
            );
        }

        if ($response->failed() || ($body['status'] ?? false) !== true) {
            $message = $body['message'] ?? 'Unknown error';

            Log::error('Paystack API rejected a request', [
                'path' => $path,
                'http_status' => $response->status(),
                'message' => $message,
            ]);

            throw new GatewayException("Paystack rejected [{$path}]: {$message}");
        }

        // A successful envelope with no data would mean the caller's expectations
        // cannot be met; hand back an empty array and let the caller's own
        // required-field checks produce the specific error.
        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * The configured secret key, or null when billing has not been set up.
     *
     * Trimmed because a key pasted into .env with a trailing space produces a 401
     * that is genuinely hard to spot by eye.
     */
    private function secretKey(): ?string
    {
        $key = trim((string) config('billing.paystack.secret_key'));

        return $key !== '' ? $key : null;
    }
}
