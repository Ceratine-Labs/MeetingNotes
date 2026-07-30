{{--
    Navbar search box with a debounced type-ahead dropdown.

    Included by core::layouts.app via @includeIf, so Core keeps no hard dependency on
    the Search module — if it is removed, the navbar simply has no search box.

    Behaviour lives in public/js/app.js (the `[data-mn-search]` handler). The debounce
    interval is served from config so it is tunable without editing JS.

    Searches transcripts, minutes sections, decisions and action items in the current
    workspace — so typing a person's name finds the meetings they appear in.
--}}
@auth
    <div class="mn-search flex-grow-1" data-mn-search>
        <form action="{{ route('search.index') }}" method="GET" role="search" autocomplete="off">
            <div class="input-icon">
                <span class="input-icon-addon">
                    {{-- Swapped for a spinner by the JS while a request is in flight. --}}
                    <i class="ti ti-search" data-mn-search-icon></i>
                </span>

                <input type="search" name="q"
                       class="form-control"
                       placeholder="Search minutes, decisions, actions, transcripts…"
                       aria-label="Search this workspace"
                       role="combobox"
                       aria-expanded="false"
                       aria-controls="mn-search-results"
                       aria-autocomplete="list"
                       value="{{ request()->routeIs('search.index') ? request()->query('q') : '' }}"
                       data-mn-search-input
                       data-mn-search-url="{{ route('search.quick') }}"
                       data-mn-search-delay="{{ config('search.debounce_ms') }}"
                       data-mn-search-min="{{ \Modules\Search\Services\SearchService::MIN_TERM_LENGTH }}">
            </div>
        </form>

        {{--
            Results panel. Hidden until there is something to show. Rendered by JS from
            the JSON endpoint rather than server-side, because it updates without a
            page load — the one place in this app where that is true.
        --}}
        <div class="mn-search-results dropdown-menu" id="mn-search-results"
             role="listbox" data-mn-search-panel hidden></div>
    </div>
@endauth
