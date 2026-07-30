@extends('core::layouts.guest')

@section('title', 'Confirm your email — ' . config('app.name'))
@section('heading', 'Confirm your email address')

@section('content')
    <div class="text-center mb-4">
        <span class="avatar avatar-lg bg-primary-lt mb-3">
            <i class="ti ti-mail-opened fs-1"></i>
        </span>
        <p class="text-secondary mb-0">
            We sent a confirmation link to <strong>{{ auth()->user()->email }}</strong>.
            Click it to unlock minutes generation.
        </p>
    </div>

    {{--
        Explains *why* the wall exists. "Verify your email" with no reason reads
        as bureaucracy; saying it protects the free allowance makes it obvious
        this is not busywork.
    --}}
    <div class="alert alert-info" role="alert">
        <div class="d-flex">
            <i class="ti ti-info-circle me-2 mt-1"></i>
            <div class="small">
                Everything else in {{ config('app.name') }} works right now — confirming your
                address is only needed before your first generation, so that free
                allowances cannot be farmed with throwaway addresses.
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn btn-primary w-100">Send the link again</button>
    </form>

    <div class="text-center mt-3">
        <a href="{{ route('auth.profile.edit') }}" class="text-secondary small">
            Wrong address? Change it in your profile
        </a>
    </div>
@endsection

@section('footer')
    <form method="POST" action="{{ route('auth.logout') }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-link text-secondary p-0">Sign out</button>
    </form>
@endsection
