@extends('core::layouts.guest')

@section('title', 'Log in — ' . config('app.name'))
@section('heading', 'Log in to your account')

@section('content')
    <form method="POST" action="{{ route('auth.login.attempt') }}" autocomplete="on">
        @csrf

        {{-- Preserved across the POST so a user who arrived from an invite link
             lands back on the invitation after signing in. --}}
        @if ($invitationToken)
            <input type="hidden" name="invitation" value="{{ $invitationToken }}">
        @endif

        <div class="mb-3">
            <label for="email" class="form-label">Email address</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   placeholder="you@company.com" autocomplete="email" required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-2">
            <label for="password" class="form-label">
                Password
                <span class="form-label-description">
                    <a href="{{ route('password.request') }}">Forgot password?</a>
                </span>
            </label>
            <input type="password" name="password" id="password"
                   class="form-control @error('password') is-invalid @enderror"
                   placeholder="Your password" autocomplete="current-password" required>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label class="form-check">
                <input type="checkbox" name="remember" class="form-check-input" value="1"
                    @checked(old('remember'))>
                <span class="form-check-label">Keep me signed in</span>
            </label>
        </div>

        <div class="form-footer">
            <button type="submit" class="btn btn-primary w-100">Log in</button>
        </div>
    </form>
@endsection

@section('footer')
    Don't have an account yet?
    <a href="{{ route('auth.register', $invitationToken ? ['invitation' => $invitationToken] : []) }}">
        Sign up
    </a>
    — free, no card required.
@endsection
