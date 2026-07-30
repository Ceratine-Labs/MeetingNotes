<?php

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Tenancy\Http\Requests\StoreOrganisationRequest;
use Modules\Tenancy\Http\Requests\UpdateOrganisationRequest;
use Modules\Tenancy\Services\OrganisationContext;
use Modules\Tenancy\Services\OrganisationResolver;
use Modules\Tenancy\Services\OrganisationService;

/**
 * Creating a workspace and editing the current one's settings.
 *
 * Thin by house convention — every rule lives in OrganisationService or the
 * FormRequests. What is left here is HTTP concerns: which view, which redirect,
 * what flash message.
 */
class OrganisationController extends Controller
{
    public function __construct(
        private readonly OrganisationService $organisations,
        private readonly OrganisationResolver $resolver,
        private readonly OrganisationContext $context,
    ) {}

    /**
     * Form for creating an additional workspace.
     *
     * Reachable two ways: deliberately, from the org switcher, and as the
     * redirect target for a signed-in user who belongs to no workspace at all
     * (see EnsureOrganisation). It therefore must NOT sit behind the
     * `organisation` middleware — that would be a redirect loop.
     */
    public function create(): View
    {
        return view('tenancy::organisations.create');
    }

    /**
     * Create the workspace and switch the user into it.
     *
     * Switching immediately is the whole point — someone who just created a
     * workspace expects to be standing in it, not to have to pick it from a
     * dropdown.
     */
    public function store(StoreOrganisationRequest $request): RedirectResponse
    {
        $organisation = $this->organisations->create(
            $request->validated('name'),
            $request->user(),
            $request->validated('timezone')
        );

        $this->resolver->switchTo($request->user(), $organisation->getKey());

        return redirect()
            ->route('core.dashboard')
            ->with('status', "Workspace \"{$organisation->name}\" is ready.");
    }

    /**
     * Settings page for the current workspace.
     */
    public function edit(): View
    {
        return view('tenancy::organisations.edit', [
            'organisation' => $this->context->getOrFail(),
        ]);
    }

    /**
     * Save workspace settings.
     */
    public function update(UpdateOrganisationRequest $request): RedirectResponse
    {
        $this->organisations->update($this->context->getOrFail(), $request->validated());

        return redirect()
            ->route('tenancy.organisations.edit')
            ->with('status', 'Workspace settings saved.');
    }

    /**
     * Switch the signed-in user into another of their workspaces.
     *
     * A POST rather than a GET: it mutates state (the user's current
     * organisation pointer), so it must be CSRF-protected and must not be
     * triggerable by a prefetch or a crafted image tag.
     *
     * A failed switch redirects back with a message rather than 403-ing. The
     * usual cause is a tab left open after being removed from the workspace,
     * and an explanation serves that person better than an error page.
     */
    public function switch(Request $request, string $organisation): RedirectResponse
    {
        if (! $this->resolver->switchTo($request->user(), $organisation)) {
            return redirect()
                ->route('core.dashboard')
                ->with('error', 'You no longer have access to that workspace.');
        }

        return redirect()->route('core.dashboard');
    }
}
