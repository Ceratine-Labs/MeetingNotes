@extends('core::layouts.guest')

@section('title', 'Join ' . $organisation->name . ' — ' . config('app.name'))
@section('heading', 'You have been invited')

@section('content')
    <div class="text-center mb-4">
        <span class="avatar avatar-lg bg-primary-lt mb-3">
            <i class="ti ti-building fs-1"></i>
        </span>
        <div class="h3 mb-1">{{ $organisation->name }}</div>
        <div class="text-secondary">
            You have been invited to join as {{ Str::lower($invitation->role) }}.
        </div>
    </div>

    @auth
        @if ($emailMismatch)
            {{--
                The invite was addressed to a different address than the one
                currently signed in. Still acceptable — people forward invites,
                and people have work and personal accounts — but say so plainly
                so nobody joins a workspace under the wrong identity by accident.
            --}}
            <div class="alert alert-warning" role="alert">
                <div class="d-flex">
                    <i class="ti ti-alert-triangle me-2 mt-1"></i>
                    <div>
                        This invitation was sent to <strong>{{ $invitation->email }}</strong>,
                        but you are signed in as <strong>{{ auth()->user()->email }}</strong>.
                        Accepting will add <strong>{{ auth()->user()->email }}</strong> to the workspace.
                    </div>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('tenancy.invitations.accept', $token) }}">
            @csrf
            <button type="submit" class="btn btn-primary w-100">
                <i class="ti ti-check me-1"></i>Accept and continue
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="{{ route('core.dashboard') }}" class="text-secondary">No thanks, take me to my workspace</a>
        </div>
    @else
        <p class="text-secondary text-center">
            Create an account or log in to accept. It takes about a minute and no card is required.
        </p>

        {{--
            The token travels in the `invitation` query parameter so that the
            registration and login flows can redirect back here once there is a
            signed-in user to accept as. The email is pre-filled to save typing;
            it is only a default and the user may change it.
        --}}
        <div class="d-grid gap-2">
            <a href="{{ route('auth.register', ['invitation' => $token, 'email' => $invitation->email]) }}"
               class="btn btn-primary">
                Create an account
            </a>
            <a href="{{ route('auth.login', ['invitation' => $token]) }}" class="btn">
                I already have an account
            </a>
        </div>
    @endauth

    <div class="text-center text-secondary small mt-4">
        This invitation expires {{ $invitation->expires_at->diffForHumans() }}.
    </div>
@endsection
