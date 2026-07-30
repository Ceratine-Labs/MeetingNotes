@extends('core::layouts.app')

@section('title', 'Workspace settings — ' . config('app.name'))
@section('page_pretitle', $organisation->name)
@section('page_title', 'Workspace settings')

@section('content')
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Details</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('tenancy.organisations.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label required">Workspace name</label>
                            <input type="text" name="name" id="name"
                                   value="{{ old('name', $organisation->name) }}"
                                   class="form-control @error('name') is-invalid @enderror"
                                   required maxlength="120">
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="timezone" class="form-label required">Timezone</label>
                            <select name="timezone" id="timezone" class="form-select"
                                    data-tom-select data-placeholder="Search timezones…">
                                @foreach (DateTimeZone::listIdentifiers() as $tz)
                                    <option value="{{ $tz }}"
                                        @selected(old('timezone', $organisation->timezone) === $tz)>
                                        {{ str_replace('_', ' ', $tz) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('timezone')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-primary">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Workspace</h3>
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt class="text-secondary small">Identifier</dt>
                        <dd><code>{{ $organisation->slug }}</code></dd>

                        <dt class="text-secondary small">Members</dt>
                        <dd>
                            {{ $organisation->seatsInUse() }}
                            <a href="{{ route('tenancy.members.index') }}" class="ms-1 small">Manage</a>
                        </dd>

                        <dt class="text-secondary small">Created</dt>
                        <dd class="mb-0">{{ $organisation->created_at->toFormattedDayDateString() }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
