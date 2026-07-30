@extends('core::layouts.guest')

@section('title', 'Invitation not valid: ' . config('app.name'))
@section('heading', 'This invitation is no longer valid')

@section('content')
    {{--
        One message covers all three failure cases — unknown token, already
        accepted, expired. That is deliberate: distinguishing them would let
        anyone holding a token work out whether a given invitation ever
        existed, and the useful next step is the same in every case.
    --}}
    <div class="text-center">
        <span class="avatar avatar-lg bg-secondary-lt mb-3">
            <i class="ti ti-mail-off fs-1"></i>
        </span>

        <p class="text-secondary">
            The link may have expired, already been used, or been withdrawn.
            Ask whoever invited you to send a fresh invitation.
        </p>

        <div class="d-grid gap-2 mt-4">
            @auth
                <a href="{{ route('core.dashboard') }}" class="btn btn-primary">Back to my workspace</a>
            @else
                <a href="{{ route('auth.login') }}" class="btn btn-primary">Log in</a>
                <a href="{{ route('site.home') }}" class="btn">About {{ config('app.name') }}</a>
            @endauth
        </div>
    </div>
@endsection
