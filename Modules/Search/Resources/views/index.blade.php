@extends('core::layouts.app')

@section('title', ($term !== '' ? '"' . $term . '" — ' : '') . 'Search — ' . config('app.name'))
@section('page_pretitle', 'Search')
@section('page_title', $term !== '' ? 'Results for "' . $term . '"' : 'Search')

@section('content')
    @if ($term === '' || mb_strlen(trim($term)) < $minLength)
        <div class="empty">
            <div class="empty-icon"><i class="ti ti-search fs-1 text-secondary"></i></div>
            <p class="empty-title h3">Search this workspace</p>
            <p class="empty-subtitle text-secondary">
                Type at least {{ $minLength }} characters in the search box above. Searches
                across transcripts, minutes, decisions and action items — so a person's name
                finds every meeting they appear in.
            </p>
        </div>
    @else
        <div class="card">
            <div class="card-header">
                {{-- Type filter. Each link preserves the term, so filtering never
                     silently loses what the user searched for. --}}
                <ul class="nav nav-pills">
                    @foreach ($types as $value => $label)
                        <li class="nav-item">
                            <a class="nav-link {{ $type === $value ? 'active' : '' }}"
                               href="{{ route('search.index', array_filter(['q' => $term, 'type' => $value])) }}">
                                {{ $label }}
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="card-actions text-secondary small">
                    {{ $results->count() }} {{ Str::plural('result', $results->count()) }}
                </div>
            </div>

            @if ($results->isEmpty())
                <div class="card-body">
                    <div class="empty p-0">
                        <p class="empty-title h4">No matches</p>
                        <p class="empty-subtitle text-secondary">
                            Nothing in this workspace matches "{{ $term }}".
                            @if ($type !== null)
                                Try <a href="{{ route('search.index', ['q' => $term]) }}">searching everything</a>.
                            @endif
                        </p>
                    </div>
                </div>
            @else
                <div class="list-group list-group-flush">
                    @foreach ($results as $result)
                        <a href="{{ route('minutes.show', $result->meeting_id) }}"
                           class="list-group-item list-group-item-action">
                            <div class="d-flex align-items-baseline gap-2 mb-1">
                                <span class="badge {{ $result->typeColour() }}">{{ $result->typeLabel() }}</span>
                                <span class="fw-semibold">
                                    {{ $result->meeting?->title ?: 'Untitled meeting' }}
                                </span>
                                @if ($result->meeting_date)
                                    <span class="text-secondary small ms-auto">
                                        {{ $result->meeting_date->toFormattedDayDateString() }}
                                    </span>
                                @endif
                            </div>

                            @if ($result->label)
                                <div class="small text-primary mb-1">{{ $result->label }}</div>
                            @endif

                            {{-- Snippet is extracted around the match at read time, so it
                                 always reflects the term actually searched. --}}
                            <div class="text-secondary small">{{ $result->snippet($term) }}</div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
@endsection
