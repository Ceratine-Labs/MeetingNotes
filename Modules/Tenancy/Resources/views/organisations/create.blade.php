@extends('core::layouts.app')

@section('title', 'New workspace — ' . config('app.name'))
@section('page_pretitle', 'Workspaces')
@section('page_title', 'Create a workspace')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <p class="text-secondary">
                        A workspace holds your meetings, minutes and members. Each one has its
                        own subscription and its own usage allowance.
                    </p>

                    <form method="POST" action="{{ route('tenancy.organisations.store') }}">
                        @csrf

                        <div class="mb-3">
                            <label for="name" class="form-label required">Workspace name</label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}"
                                   class="form-control @error('name') is-invalid @enderror"
                                   placeholder="Acme Holdings" required autofocus maxlength="120">
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-hint">Usually your company, department or team name.</small>
                        </div>

                        <div class="mb-3">
                            <label for="timezone" class="form-label">Timezone</label>
                            {{--
                                Tom Select (house standard for searchable
                                selects) — there are ~400 timezone entries and a
                                bare <select> is unusable at that length.
                            --}}
                            <select name="timezone" id="timezone" class="form-select"
                                    data-tom-select data-placeholder="Search timezones…">
                                @foreach (DateTimeZone::listIdentifiers() as $tz)
                                    <option value="{{ $tz }}"
                                        @selected(old('timezone', config('tenancy.default_timezone')) === $tz)>
                                        {{ str_replace('_', ' ', $tz) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('timezone')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                            <small class="form-hint">
                                Meeting dates and times in your minutes are rendered in this zone.
                            </small>
                        </div>

                        <div class="d-flex">
                            <a href="{{ route('core.dashboard') }}" class="btn btn-link text-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary ms-auto">
                                <i class="ti ti-plus me-1"></i>Create workspace
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
