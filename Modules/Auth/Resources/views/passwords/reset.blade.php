@extends('core::layouts.guest')

@section('title', 'Set a new password: ' . config('app.name'))
@section('heading', 'Set a new password')

@section('content')
    <form method="POST" action="{{ route('password.update') }}">
        @csrf

        {{-- Both come from the emailed link. The token is single-use and
             verified server-side by Laravel's password broker. --}}
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="mb-3">
            <label for="email" class="form-label required">Email address</label>
            <input type="email" name="email" id="email" value="{{ old('email', $email) }}"
                   class="form-control @error('email') is-invalid @enderror"
                   autocomplete="email" required>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label required">New password</label>
            <input type="password" name="password" id="password"
                   class="form-control @error('password') is-invalid @enderror"
                   autocomplete="new-password" required autofocus>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <small class="form-hint">At least 10 characters, with letters and numbers.</small>
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label required">Confirm new password</label>
            <input type="password" name="password_confirmation" id="password_confirmation"
                   class="form-control" autocomplete="new-password" required>
        </div>

        <div class="form-footer">
            <button type="submit" class="btn btn-primary w-100">Set new password</button>
        </div>
    </form>
@endsection
