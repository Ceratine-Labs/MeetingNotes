{{--
    Shared end-of-body scripts for every layout.

    Load order matters and is not arbitrary:
      1. Tabler   — needs to exist before any markup-driven component inits.
      2. Vendors  — SweetAlert2 and Tom Select are globals app.js checks for.
      3. app.js   — our delegated handlers; degrades quietly if a vendor above
                    was skipped for this page.

    Opt-in bundles, declared in the page as `@section('charts', true)` (same
    pattern as the optional stylesheets in head.blade.php), so no page ships
    JS it never calls:
      @section('charts', true) — ApexCharts (usage graphs, admin analytics)
      @section('tour', true)   — IntroJS (guided tours)
--}}
<script src="{{ mn_asset('vendor/tabler/js/tabler.min.js') }}" defer></script>
<script src="{{ mn_asset('vendor/sweetalert2/sweetalert2.min.js') }}" defer></script>
<script src="{{ mn_asset('vendor/tom-select/tom-select.complete.min.js') }}" defer></script>

@hasSection('charts')
    <script src="{{ mn_asset('vendor/apexcharts/apexcharts.min.js') }}" defer></script>
@endif

@hasSection('tour')
    <script src="{{ mn_asset('vendor/introjs/intro.min.js') }}" defer></script>
@endif

{{-- defer on all of the above means they execute in order, before DOMContentLoaded. --}}
<script src="{{ mn_asset('js/app.js') }}" defer></script>

@stack('scripts')
