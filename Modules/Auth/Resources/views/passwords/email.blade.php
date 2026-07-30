@extends('core::layouts.guest')

@section('title', 'Forgot password: ' . config('app.name'))
@section('heading', 'Forgot your password?')

@section('content')
    <p class="text-secondary">
        Enter the email address on your account and we'll send you a link to set a new password.
    </p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label required">Email address</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   placeholder="you@company.com" autocomplete="email" required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-footer">
            <button type="submit" class="btn btn-primary w-100">
                <i class="ti ti-mail me-1"></i>Send reset link
            </button>
        </div>
    </form>
@endsection

@section('footer')
    <a href="{{ route('auth.login') }}">Back to log in</a>
@endsection
