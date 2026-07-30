@extends('core::layouts.app')

@section('title', 'Members — ' . config('app.name'))
@section('page_pretitle', $organisation->name)
@section('page_title', 'Members')

@section('content')
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        {{ $memberships->count() }} {{ Str::plural('member', $memberships->count()) }}
                    </h3>
                    @if ($seatsRemaining !== null)
                        <div class="card-actions text-secondary small">
                            {{ $seatsRemaining }} {{ Str::plural('seat', $seatsRemaining) }} available
                        </div>
                    @endif
                </div>

                <div class="table-responsive">
                    <table class="table card-table table-vcenter">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($memberships as $membership)
                                <tr>
                                    <td>
                                        <div>{{ $membership->user->name }}</div>
                                        <div class="text-secondary small">{{ $membership->user->email }}</div>
                                    </td>
                                    <td>
                                        @if ($membership->isOwner())
                                            {{-- The owner's role is fixed here; it changes only
                                                 through an explicit ownership transfer. --}}
                                            <span class="badge bg-primary-lt">Owner</span>
                                        @else
                                            <form method="POST"
                                                  action="{{ route('tenancy.members.role', $membership) }}"
                                                  class="d-flex align-items-center gap-2">
                                                @csrf
                                                @method('PUT')
                                                <select name="role" class="form-select form-select-sm w-auto"
                                                        onchange="this.form.requestSubmit()">
                                                    @foreach ($assignableRoles as $role)
                                                        <option value="{{ $role }}"
                                                            @selected($membership->role === $role)>
                                                            {{ ucfirst($role) }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <noscript>
                                                    <button class="btn btn-sm">Save</button>
                                                </noscript>
                                            </form>
                                        @endif
                                    </td>
                                    <td>
                                        @if (! $membership->isOwner() && $membership->user_id !== auth()->id())
                                            {{-- data-confirm is intercepted by app.js and gated
                                                 behind SweetAlert2 (house rule: no native confirm). --}}
                                            <form method="POST"
                                                  action="{{ route('tenancy.members.destroy', $membership) }}"
                                                  data-confirm="{{ $membership->user->name }} will lose access to this workspace immediately."
                                                  data-confirm-title="Remove this member?"
                                                  data-confirm-button="Yes, remove"
                                                  data-confirm-danger="1">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-ghost-danger" title="Remove member">
                                                    <i class="ti ti-user-minus"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($invitations->isNotEmpty())
                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title">Pending invitations</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Expires</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($invitations as $invitation)
                                    <tr>
                                        <td>{{ $invitation->email }}</td>
                                        <td>{{ ucfirst($invitation->role) }}</td>
                                        <td class="text-secondary">
                                            {{ $invitation->expires_at->diffForHumans() }}
                                        </td>
                                        <td>
                                            <form method="POST"
                                                  action="{{ route('tenancy.invitations.destroy', $invitation) }}"
                                                  data-confirm="The invitation link emailed to {{ $invitation->email }} will stop working."
                                                  data-confirm-title="Withdraw this invitation?"
                                                  data-confirm-button="Yes, withdraw"
                                                  data-confirm-danger="1">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-ghost-danger" title="Withdraw invitation">
                                                    <i class="ti ti-x"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Invite someone</h3>
                </div>
                <div class="card-body">
                    @if ($canInvite)
                        <form method="POST" action="{{ route('tenancy.invitations.store') }}">
                            @csrf

                            <div class="mb-3">
                                <label for="email" class="form-label required">Email address</label>
                                <input type="email" name="email" id="email" value="{{ old('email') }}"
                                       class="form-control @error('email') is-invalid @enderror"
                                       placeholder="colleague@company.com" required>
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="role" class="form-label">Role</label>
                                <select name="role" id="role" class="form-select">
                                    @foreach ($assignableRoles as $role)
                                        <option value="{{ $role }}" @selected(old('role', 'member') === $role)>
                                            {{ ucfirst($role) }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="form-hint">
                                    Members create and edit minutes. Admins can also manage
                                    members and workspace settings.
                                </small>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="ti ti-send me-1"></i>Send invitation
                            </button>
                        </form>
                    @else
                        <div class="empty p-0">
                            <div class="empty-icon"><i class="ti ti-users-group fs-1 text-secondary"></i></div>
                            <p class="empty-title h4">No seats available</p>
                            <p class="empty-subtitle text-secondary">{{ $seatLimitMessage }}</p>
                            @if (Route::has('billing.plans'))
                                <div class="empty-action">
                                    <a href="{{ route('billing.plans') }}" class="btn btn-primary">
                                        <i class="ti ti-arrow-up me-1"></i>Upgrade plan
                                    </a>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
