<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validation for self-service registration.
 *
 * Public, unauthenticated, and the single most-attacked form in the product, so
 * three defences beyond the obvious field rules:
 *
 *   1. Rate limiting (on the route, not here).
 *   2. A honeypot field — see the `website` rule below.
 *   3. Password strength via Laravel's Password rule, including a check against
 *      known-compromised password lists in production only (it needs an
 *      outbound HTTPS call, which must not make local development or the test
 *      suite depend on the network).
 */
class RegisterRequest extends FormRequest
{
    /**
     * Anyone may register.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],

            // The dns check (MX lookup) guards production signups against typo
            // domains, but it makes every offline environment — dev boxes, CI
            // runners, the E2E suite — reject all addresses. Strict where it
            // pays, permissive where it lies.
            'email' => ['required', app()->isProduction() ? 'email:rfc,dns' : 'email:rfc', 'max:255', 'unique:users,email'],

            // `confirmed` pairs with password_confirmation. Length 10 rather
            // than Laravel's default 8: this account can hold a customer's
            // entire meeting history.
            'password' => ['required', 'confirmed', $this->passwordRules()],

            // Optional. Blank means "name it after the person" — see
            // RegisterController::workspaceNameFor().
            'organisation_name' => ['nullable', 'string', 'min:2', 'max:120'],

            // Carried through from an invite link; validated as a shape only.
            // Whether it is a *real* pending invitation is decided by
            // InvitationService, because an expired token must not produce a
            // validation error that blocks registration — the person should
            // still get an account.
            'invitation' => ['nullable', 'string', 'size:64'],

            // Honeypot. A real browser leaves this hidden field empty; most
            // naive bots fill every input they find. `prohibited` rejects any
            // non-empty value outright.
            'website' => ['prohibited'],

            'terms' => ['accepted'],
        ];
    }

    /**
     * Password strength rules.
     *
     * uncompromised() is production-only on purpose: it calls the Have I Been
     * Pwned range API, and a test suite or a dev machine on a bad connection
     * must not fail registration because of an outbound HTTP timeout.
     */
    private function passwordRules(): Password
    {
        $rules = Password::min(10)->letters()->numbers();

        return app()->environment('production')
            ? $rules->uncompromised()
            : $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'website.prohibited' => 'That submission looked automated. Please try again.',
            'terms.accepted' => 'Please accept the terms of service to continue.',
            'email.unique' => 'An account with that email already exists. Try logging in instead.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organisation_name' => 'workspace name',
        ];
    }

    /**
     * Normalise the email before the unique check runs, so "Sam@Acme.com"
     * cannot create a second account alongside "sam@acme.com".
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
