@extends('core::layouts.guest')

@section('title', 'Set back-office password — ' . config('app.name'))
@section('heading', 'Set a new password')

@section('content')
    <form method="POST" action="{{ route('admin.password.update') }}">
        @csrf

        {{-- From the emailed link. Single-use, verified by the `admins` password
             broker — not the customer broker. --}}
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
            {{-- Stricter than the customer requirement (10 chars, letters + numbers):
                 these accounts can read every customer's billing data. --}}
            <small class="form-hint">
                At least 12 characters, with letters, numbers and a symbol.
            </small>
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
