@extends('core::layouts.app')

@section('title', 'Profile — ' . config('app.name'))
@section('page_pretitle', 'Your account')
@section('page_title', 'Profile')

@section('content')
    <div class="row row-cards">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Your details</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('auth.profile.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label required">Name</label>
                            <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}"
                                   class="form-control @error('name') is-invalid @enderror"
                                   required maxlength="120">
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label required">Email address</label>
                            <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}"
                                   class="form-control @error('email') is-invalid @enderror" required>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-hint">
                                Changing this requires confirming the new address before you can
                                generate minutes again.
                            </small>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-primary">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h3 class="card-title">Change password</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('auth.profile.password') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="current_password" class="form-label required">Current password</label>
                            <input type="password" name="current_password" id="current_password"
                                   class="form-control @error('current_password') is-invalid @enderror"
                                   autocomplete="current-password" required>
                            @error('current_password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label required">New password</label>
                            <input type="password" name="password" id="new_password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   autocomplete="new-password" required>
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

                        <div class="text-end">
                            <button type="submit" class="btn btn-primary">Change password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Account</h3>
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt class="text-secondary small">Email confirmed</dt>
                        <dd>
                            @if ($user->hasVerifiedEmail())
                                <span class="badge bg-success-lt">
                                    <i class="ti ti-check me-1"></i>Confirmed
                                </span>
                            @else
                                <span class="badge bg-warning-lt">
                                    <i class="ti ti-alert-triangle me-1"></i>Not confirmed
                                </span>
                                <a href="{{ route('verification.notice') }}" class="ms-2 small">Confirm now</a>
                            @endif
                        </dd>

                        <dt class="text-secondary small">Workspaces</dt>
                        <dd>{{ $user->memberships()->count() }}</dd>

                        @if ($user->last_login_at)
                            <dt class="text-secondary small">Last sign-in</dt>
                            <dd class="mb-0">{{ $user->last_login_at->diffForHumans() }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
