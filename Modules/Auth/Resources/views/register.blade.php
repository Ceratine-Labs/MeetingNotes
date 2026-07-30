@extends('core::layouts.guest')

@section('title', 'Create your account — ' . config('app.name'))
@section('heading', $invitation ? 'Join ' . $invitation->organisation->name : 'Create your free account')

@section('content')
    @if ($invitation)
        <div class="alert alert-info" role="alert">
            <div class="d-flex">
                <i class="ti ti-mail me-2 mt-1"></i>
                <div>
                    You were invited to join <strong>{{ $invitation->organisation->name }}</strong>
                    as {{ Str::lower($invitation->role) }}. Create your account to accept.
                </div>
            </div>
        </div>
    @else
        <p class="text-secondary text-center mb-4">
            Three free sets of minutes every month. No card required.
        </p>
    @endif

    <form method="POST" action="{{ route('auth.register.store') }}">
        @csrf

        {{-- Carried through the POST so RegistrationService adds the new user to
             the inviting workspace instead of creating a fresh one. --}}
        @if ($invitationToken)
            <input type="hidden" name="invitation" value="{{ $invitationToken }}">
        @endif

        {{--
            Honeypot. Hidden from people (and from screen readers via
            aria-hidden + tabindex), but present in the DOM where naive bots fill
            every input they find. Any value at all fails the `prohibited` rule
            in RegisterRequest.

            Deliberately named "website" rather than "honeypot" — a bot that
            reads field names skips the obvious trap.
        --}}
        <div class="d-none" aria-hidden="true">
            <label for="website">Website</label>
            <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="mb-3">
            <label for="name" class="form-label required">Your name</label>
            <input type="text" name="name" id="name" value="{{ old('name') }}"
                   class="form-control @error('name') is-invalid @enderror"
                   placeholder="Ryan Cruickshank" autocomplete="name" required autofocus maxlength="120">
            @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="email" class="form-label required">Email address</label>
            <input type="email" name="email" id="email"
                   value="{{ old('email', $prefilledEmail) }}"
                   class="form-control @error('email') is-invalid @enderror"
                   placeholder="you@company.com" autocomplete="email"
                   {{-- An invited address is fixed: changing it here would create
                        an account that does not match the invitation. --}}
                   @readonly($invitation !== null)
                   required>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        {{-- Hidden when joining an existing workspace — they are not creating one. --}}
        @unless ($invitation)
            <div class="mb-3">
                <label for="organisation_name" class="form-label">Workspace name</label>
                <input type="text" name="organisation_name" id="organisation_name"
                       value="{{ old('organisation_name') }}"
                       class="form-control @error('organisation_name') is-invalid @enderror"
                       placeholder="Your company or team" autocomplete="organization" maxlength="120">
                @error('organisation_name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <small class="form-hint">Optional — we'll name it after you and you can change it later.</small>
            </div>
        @endunless

        <div class="mb-3">
            <label for="password" class="form-label required">Password</label>
            <input type="password" name="password" id="password"
                   class="form-control @error('password') is-invalid @enderror"
                   autocomplete="new-password" required>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <small class="form-hint">At least 10 characters, with letters and numbers.</small>
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label required">Confirm password</label>
            <input type="password" name="password_confirmation" id="password_confirmation"
                   class="form-control" autocomplete="new-password" required>
        </div>

        <div class="mb-3">
            <label class="form-check">
                <input type="checkbox" name="terms" value="1"
                       class="form-check-input @error('terms') is-invalid @enderror"
                       @checked(old('terms')) required>
                <span class="form-check-label">
                    I agree to the <a href="{{ route('site.terms') }}" target="_blank">terms of service</a>
                    and <a href="{{ route('site.privacy') }}" target="_blank">privacy policy</a>.
                </span>
                @error('terms')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </label>
        </div>

        <div class="form-footer">
            <button type="submit" class="btn btn-primary w-100">
                {{ $invitation ? 'Create account and join' : 'Create my account' }}
            </button>
        </div>
    </form>
@endsection

@section('footer')
    Already have an account?
    <a href="{{ route('auth.login', $invitationToken ? ['invitation' => $invitationToken] : []) }}">Log in</a>
@endsection
