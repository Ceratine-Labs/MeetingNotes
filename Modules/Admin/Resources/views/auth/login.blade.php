@extends('core::layouts.guest')

@section('title', 'Back office — ' . config('app.name'))
@section('heading', 'Back office')

@section('content')
    {{--
        Visually distinct from the customer login on purpose. Staff should be able to
        tell at a glance which login they are looking at, and nobody who lands here by
        accident should mistake it for the product sign-in.
    --}}
    <div class="text-center mb-4">
        <span class="avatar avatar-lg bg-red-lt mb-2">
            <i class="ti ti-shield-lock fs-1"></i>
        </span>
        <div class="text-secondary small">
            Staff access only. This is separate from the customer login.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.login.attempt') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email address</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   autocomplete="email" required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-2">
            <label for="password" class="form-label">
                Password
                <span class="form-label-description">
                    <a href="{{ route('admin.password.request') }}">Forgot password?</a>
                </span>
            </label>
            <input type="password" name="password" id="password"
                   class="form-control @error('password') is-invalid @enderror"
                   autocomplete="current-password" required>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label class="form-check">
                <input type="checkbox" name="remember" class="form-check-input" value="1">
                <span class="form-check-label">Keep me signed in</span>
            </label>
        </div>

        <div class="form-footer">
            <button type="submit" class="btn btn-primary w-100">Sign in</button>
        </div>
    </form>
@endsection

@section('footer')
    <a href="{{ route('auth.login') }}" class="text-secondary">Customer login</a>
@endsection
