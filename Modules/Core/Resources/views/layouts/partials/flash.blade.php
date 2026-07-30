{{--
    Flash message rendering.

    Two mechanisms, deliberately:

      * Transient confirmations ("Minutes generated", "Settings saved") become
        SweetAlert2 toasts. They are emitted as hidden <div data-mn-toast>
        stubs that public/js/app.js converts on load — so the message never
        occupies layout space and never pushes content down.

      * Validation errors render as a real, persistent alert. A toast that
        disappears after four seconds is the wrong shape for "here is what you
        must fix before this form will submit".

    Session keys honoured: status / success (success toast), info, warning,
    error (error toast).
--}}

@foreach (['status' => 'success', 'success' => 'success', 'info' => 'info', 'warning' => 'warning', 'error' => 'error'] as $key => $icon)
    @if (session()->has($key))
        <div class="d-none" data-mn-toast="{{ $icon }}">{{ session($key) }}</div>
    @endif
@endforeach

@if ($errors->any())
    <div class="alert alert-danger alert-dismissible" role="alert">
        <div class="d-flex">
            <i class="ti ti-alert-triangle me-2 mt-1"></i>
            <div>
                <h4 class="alert-title mb-1">
                    {{ $errors->count() === 1 ? 'There is a problem with your submission' : 'There are problems with your submission' }}
                </h4>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <a class="btn-close" data-bs-dismiss="alert" aria-label="close"></a>
    </div>
@endif
