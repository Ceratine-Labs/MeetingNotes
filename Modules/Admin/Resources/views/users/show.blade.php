@extends('admin::layouts.app')

@section('title', $user->name . ' — back office')
@section('page_pretitle', 'User')
@section('page_title', $user->name)

@section('content')
    <div class="row row-cards">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Account</h3></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-secondary">Email</dt>
                        <dd class="col-sm-8">{{ $user->email }}</dd>

                        <dt class="col-sm-4 text-secondary">Email confirmed</dt>
                        <dd class="col-sm-8">
                            @if ($user->hasVerifiedEmail())
                                <span class="badge bg-green-lt">
                                    {{ $user->email_verified_at->toFormattedDayDateString() }}
                                </span>
                            @else
                                {{-- Worth surfacing: an unconfirmed account cannot
                                     generate, which is the usual cause of
                                     "nothing happens when I click generate". --}}
                                <span class="badge bg-yellow-lt">Not confirmed</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-secondary">Registered</dt>
                        <dd class="col-sm-8">{{ $user->created_at->toFormattedDayDateString() }}</dd>

                        <dt class="col-sm-4 text-secondary">Last sign-in</dt>
                        <dd class="col-sm-8">
                            {{ $user->last_login_at?->toDayDateTimeString() ?? 'Never' }}
                            @if ($user->last_login_ip)
                                <span class="text-secondary small">from {{ $user->last_login_ip }}</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-secondary">Workspaces</dt>
                        <dd class="col-sm-8 mb-0">
                            @forelse ($user->memberships as $membership)
                                <div>
                                    @if ($membership->organisation)
                                        <a href="{{ route('admin.organisations.show', $membership->organisation) }}">
                                            {{ $membership->organisation->name }}
                                        </a>
                                        <span class="text-secondary small">({{ $membership->roleLabel() }})</span>
                                    @endif
                                </div>
                            @empty
                                <span class="text-secondary">None</span>
                            @endforelse
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title">Back-office actions on this account</h3></div>
                <div class="table-responsive">
                    <table class="table card-table table-vcenter">
                        <thead><tr><th>Action</th><th>By</th><th>When</th></tr></thead>
                        <tbody>
                            @forelse ($auditTrail as $entry)
                                <tr>
                                    <td>
                                        {{ $entry->label() }}
                                        @if (data_get($entry->context, 'reason'))
                                            <div class="text-secondary small">
                                                {{ data_get($entry->context, 'reason') }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-secondary">{{ $entry->admin_email ?? '—' }}</td>
                                    <td class="text-secondary">{{ $entry->created_at->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-secondary">Nothing recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-warning">
                <div class="card-header">
                    <h3 class="card-title"><i class="ti ti-user-check me-1"></i>Sign in as this user</h3>
                </div>
                <div class="card-body">
                    {{-- The sharpest tool in the back office, so the consequences are
                         stated on the button rather than hidden in a tooltip. --}}
                    <p class="text-secondary small">
                        You will be signed out of the back office and signed in as
                        <strong>{{ $user->email }}</strong>. This is recorded in the audit log.
                        To return, sign out and log in to the back office again.
                    </p>

                    <form method="POST" action="{{ route('admin.users.impersonate', $user) }}"
                          data-confirm="You will be signed out of the back office and signed in as {{ $user->email }}. This is recorded in the audit log."
                          data-confirm-title="Sign in as this user?"
                          data-confirm-button="Yes, sign in as them"
                          data-confirm-danger="1">
                        @csrf

                        <div class="mb-3">
                            <label for="reason" class="form-label required">Reason</label>
                            <textarea name="reason" id="reason" rows="2" class="form-control"
                                      placeholder="Reproducing the export failure they reported"
                                      required minlength="5"></textarea>
                        </div>

                        <button type="submit" class="btn btn-warning w-100">
                            <i class="ti ti-user-check me-1"></i>Sign in as {{ $user->name }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
