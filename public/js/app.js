/**
 * MeetingNotes application JavaScript.
 *
 * Vanilla JS by house rule — no jQuery, React, Vue or Alpine. Everything here
 * is delegated from `document`, so markup rendered later (Blade partial swap,
 * a fetch that replaces a section) is wired up without re-running any init.
 *
 * Loaded as a classic script (not a module) at the end of <body>, after the
 * vendored Tabler / SweetAlert2 / Tom Select bundles. It assumes those globals
 * exist but degrades quietly if one is absent, so a page can choose to omit a
 * bundle it does not need.
 *
 * Served straight out of public/js — there is deliberately no bundler step.
 * See docs/VENDOR_ASSETS.md for why (no Node on the deploy target).
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------ *
     * Shared helpers
     * ------------------------------------------------------------------ */

    /**
     * Read the CSRF token Blade puts in <meta name="csrf-token">. Every
     * same-origin POST from this file needs it or Laravel returns 419.
     */
    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Write a first-party cookie. Used for the guest theme preference — a
     * cookie rather than localStorage specifically so PHP can read it during
     * render and stamp data-bs-theme on <html> before first paint.
     *
     * @param {string} name
     * @param {string} value
     * @param {number} days How long the preference should survive.
     */
    function setCookie(name, value, days) {
        const expires = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie =
            name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax';
    }

    /* ------------------------------------------------------------------ *
     * Theme toggle
     *
     * The server has already decided the theme for this request. The toggle
     * only has to flip the attribute (instant, no reload), persist a cookie so
     * the NEXT request renders correctly, and — for a logged-in user — save the
     * choice against their row so it follows them to another browser.
     * ------------------------------------------------------------------ */

    const THEME_COOKIE = 'mn_theme';

    /**
     * Apply a theme to the document and persist it.
     *
     * @param {'light'|'dark'} theme
     */
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        setCookie(THEME_COOKIE, theme, 365);

        // Keep every toggle on the page (navbar + user menu) visually in sync.
        document.querySelectorAll('[data-mn-theme-toggle]').forEach(function (el) {
            el.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        });

        // Persist server-side for authenticated users. Fire-and-forget: the
        // cookie already covers this browser, so a failed save is cosmetic and
        // must never block the UI or surface an error dialog.
        const endpoint = document.body.dataset.themeSaveUrl;
        if (!endpoint) {
            return;
        }

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ theme: theme }),
        }).catch(function () {
            /* Offline or session expired — cookie fallback is enough. */
        });
    }

    document.addEventListener('click', function (event) {
        const toggle = event.target.closest('[data-mn-theme-toggle]');
        if (!toggle) {
            return;
        }

        event.preventDefault();

        // An explicit value on the control wins (two separate light/dark
        // buttons); otherwise the control behaves as a flip-flop.
        const requested = toggle.dataset.mnThemeToggle;
        const current = document.documentElement.getAttribute('data-bs-theme') || 'light';

        applyTheme(requested === 'light' || requested === 'dark' ? requested : current === 'dark' ? 'light' : 'dark');
    });

    /* ------------------------------------------------------------------ *
     * Confirmations — SweetAlert2 for everything
     *
     * House rule: no native alert/confirm/prompt. Any form or link carrying
     * data-confirm="…" is intercepted and gated behind a SweetAlert2 dialog.
     * ------------------------------------------------------------------ */

    /**
     * Build the SweetAlert2 options for a confirmation, reading the optional
     * data-confirm-* overrides off the triggering element.
     *
     * @param {HTMLElement} el
     * @returns {object} SweetAlert2 config
     */
    function confirmOptions(el) {
        return {
            title: el.dataset.confirmTitle || 'Are you sure?',
            text: el.dataset.confirm,
            icon: el.dataset.confirmIcon || 'warning',
            showCancelButton: true,
            confirmButtonText: el.dataset.confirmButton || 'Yes, continue',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            // Danger actions (delete, cancel subscription) get a red confirm.
            confirmButtonColor: el.dataset.confirmDanger ? '#d63939' : '#066fd1',
            cancelButtonColor: 'transparent',
            customClass: { cancelButton: 'btn btn-link text-secondary' },
        };
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;

        // `confirmed` is set by us just before re-submitting — without it the
        // re-submit would be intercepted again and loop forever.
        if (!form.dataset.confirm || form.dataset.confirmed) {
            return;
        }

        // No SweetAlert2 on the page: let the submit through rather than
        // silently swallowing the user's action.
        if (typeof window.Swal === 'undefined') {
            return;
        }

        event.preventDefault();

        window.Swal.fire(confirmOptions(form)).then(function (result) {
            if (result.isConfirmed) {
                form.dataset.confirmed = '1';
                form.requestSubmit();
            }
        });
    });

    document.addEventListener('click', function (event) {
        const link = event.target.closest('a[data-confirm]');
        if (!link || typeof window.Swal === 'undefined') {
            return;
        }

        event.preventDefault();

        window.Swal.fire(confirmOptions(link)).then(function (result) {
            if (result.isConfirmed) {
                window.location.href = link.href;
            }
        });
    });

    /* ------------------------------------------------------------------ *
     * Flash messages as toasts
     *
     * Blade renders flash payloads into <div data-mn-toast> stubs; we convert
     * them to SweetAlert2 toasts so they cannot push the layout around.
     * ------------------------------------------------------------------ */

    function renderToasts() {
        if (typeof window.Swal === 'undefined') {
            return;
        }

        document.querySelectorAll('[data-mn-toast]:not([data-mn-toast-shown])').forEach(function (node) {
            node.setAttribute('data-mn-toast-shown', '1');

            window.Swal.fire({
                toast: true,
                position: 'top-end',
                icon: node.dataset.mnToast || 'success',
                title: node.textContent.trim(),
                showConfirmButton: false,
                timer: 4500,
                timerProgressBar: true,
            });
        });
    }

    /* ------------------------------------------------------------------ *
     * Tom Select — every searchable / multi select
     *
     * Opt in from Blade with `data-tom-select`. Idempotent, so it is safe to
     * call again after injecting markup.
     * ------------------------------------------------------------------ */

    function initTomSelect(root) {
        if (typeof window.TomSelect === 'undefined') {
            return;
        }

        (root || document).querySelectorAll('[data-tom-select]:not(.tomselected)').forEach(function (el) {
            new window.TomSelect(el, {
                create: el.hasAttribute('data-tom-create'),
                allowEmptyOption: true,
                plugins: el.multiple ? ['remove_button'] : [],
                placeholder: el.dataset.placeholder || undefined,
            });
        });
    }

    /* ------------------------------------------------------------------ *
     * Copy-to-clipboard
     *
     * Used for the invite link and the generated minutes markdown export.
     * ------------------------------------------------------------------ */

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-mn-copy]');
        if (!button) {
            return;
        }

        event.preventDefault();

        // Either an explicit string, or the value/text of a referenced element.
        const source = button.dataset.mnCopy;
        const target = source ? document.querySelector(source) : null;
        const text = target ? target.value || target.textContent : button.dataset.mnCopyText || '';

        navigator.clipboard.writeText(text.trim()).then(function () {
            if (typeof window.Swal !== 'undefined') {
                window.Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Copied to clipboard',
                    showConfirmButton: false,
                    timer: 2000,
                });
            }
        });
    });

    /* ------------------------------------------------------------------ *
     * Character / word counters — the transcript paste box shows the user how
     * much material they have given the model before they spend a credit.
     * ------------------------------------------------------------------ */

    document.addEventListener('input', function (event) {
        const field = event.target.closest('[data-mn-wordcount]');
        if (!field) {
            return;
        }

        const output = document.querySelector(field.dataset.mnWordcount);
        if (!output) {
            return;
        }

        const words = field.value.trim() ? field.value.trim().split(/\s+/).length : 0;
        output.textContent = words.toLocaleString() + (words === 1 ? ' word' : ' words');
    });

    /* ------------------------------------------------------------------ *
     * Workspace search — debounced type-ahead
     *
     * Searches transcripts, minutes sections, decisions and action items in the
     * current workspace, so typing a person's name finds the meetings they
     * appear in.
     *
     * The debounce timer RESETS on every keystroke, so a continuous typist
     * triggers exactly one request — when they stop. The interval comes from
     * the server (config search.debounce_ms, 1.5s) rather than being hardcoded
     * here, so it is tunable without editing this file.
     * ------------------------------------------------------------------ */

    /**
     * Set up one search box. Idempotent, so it is safe to call again.
     *
     * @param {HTMLElement} root The [data-mn-search] wrapper.
     */
    function initSearch(root) {
        if (root.dataset.mnSearchReady) {
            return;
        }
        root.dataset.mnSearchReady = '1';

        const input = root.querySelector('[data-mn-search-input]');
        const panel = root.querySelector('[data-mn-search-panel]');
        const icon = root.querySelector('[data-mn-search-icon]');

        if (!input || !panel) {
            return;
        }

        const delay = parseInt(input.dataset.mnSearchDelay, 10) || 1500;
        const minLength = parseInt(input.dataset.mnSearchMin, 10) || 2;
        const endpoint = input.dataset.mnSearchUrl;

        let timer = null;
        // Aborts the previous request when a new one starts, so a slow earlier
        // response cannot land after a newer one and show stale results.
        let controller = null;

        function setBusy(busy) {
            if (!icon) {
                return;
            }
            icon.className = busy ? 'ti ti-loader-2 mn-spin' : 'ti ti-search';
        }

        function close() {
            panel.hidden = true;
            panel.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
        }

        function open() {
            panel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        /**
         * Render the JSON payload into the dropdown.
         *
         * Every value is written via textContent, never innerHTML — the snippets
         * come from meeting transcripts, which is user-supplied content and must
         * never be interpreted as markup.
         */
        function render(payload) {
            panel.innerHTML = '';

            if (!payload.results || payload.results.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'dropdown-item-text text-secondary small';
                empty.textContent = payload.too_short
                    ? 'Keep typing to search…'
                    : 'No matches in this workspace.';
                panel.appendChild(empty);
                open();
                return;
            }

            payload.results.forEach(function (result) {
                const item = document.createElement('a');
                item.className = 'dropdown-item mn-search-result';
                item.href = result.url;
                item.setAttribute('role', 'option');

                const title = document.createElement('div');
                title.className = 'd-flex align-items-baseline gap-2';

                const name = document.createElement('span');
                name.className = 'fw-semibold text-truncate';
                name.textContent = result.title;
                title.appendChild(name);

                if (result.hits > 1) {
                    const hits = document.createElement('span');
                    hits.className = 'badge bg-secondary-lt ms-auto flex-shrink-0';
                    hits.textContent = result.hits + ' matches';
                    title.appendChild(hits);
                }

                item.appendChild(title);

                if (result.label) {
                    const label = document.createElement('div');
                    label.className = 'small text-primary';
                    label.textContent = result.label;
                    item.appendChild(label);
                }

                if (result.snippet) {
                    const snippet = document.createElement('div');
                    snippet.className = 'small text-secondary text-truncate';
                    snippet.textContent = result.snippet;
                    item.appendChild(snippet);
                }

                panel.appendChild(item);
            });

            const all = document.createElement('a');
            all.className = 'dropdown-item text-center small border-top';
            all.href = payload.all_url;
            all.textContent = 'See all results';
            panel.appendChild(all);

            open();
        }

        function run() {
            const term = input.value.trim();

            if (term.length < minLength) {
                close();
                return;
            }

            if (controller) {
                controller.abort();
            }
            controller = new AbortController();

            setBusy(true);

            fetch(endpoint + '?q=' + encodeURIComponent(term), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            })
                .then(function (response) {
                    return response.ok ? response.json() : Promise.reject(response.status);
                })
                .then(render)
                .catch(function (error) {
                    // An abort is normal — it means the user kept typing.
                    if (error !== 20 && error && error.name !== 'AbortError') {
                        close();
                    }
                })
                .finally(function () {
                    setBusy(false);
                });
        }

        input.addEventListener('input', function () {
            // Reset on every keystroke: this is what makes the delay "quiet for
            // 1.5 seconds" rather than "every 1.5 seconds".
            clearTimeout(timer);
            timer = setTimeout(run, delay);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
                input.blur();
                return;
            }

            // Enter submits the form and lands on the full results page, so a
            // decisive user never has to wait out the debounce.
            if (event.key === 'Enter') {
                clearTimeout(timer);
            }

            // Down-arrow moves into the results, for keyboard-only use.
            if (event.key === 'ArrowDown' && !panel.hidden) {
                const first = panel.querySelector('.mn-search-result');
                if (first) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });

        // Arrow-cycling once inside the list. Bound on the panel rather than each
        // result so it keeps working after a re-render replaces every row.
        panel.addEventListener('keydown', function (event) {
            const items = Array.from(panel.querySelectorAll('.mn-search-result, .dropdown-item'));
            const index = items.indexOf(document.activeElement);

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                // Wraps to the top rather than dead-ending at the last result.
                (items[index + 1] || items[0]).focus();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                // From the first item, Up returns to the input — where someone
                // pressing Up is almost always trying to edit their search.
                if (index <= 0) {
                    input.focus();
                } else {
                    items[index - 1].focus();
                }
            } else if (event.key === 'Escape') {
                close();
                input.focus();
            }
        });

        // Click-away closes it. Bound on document rather than using `blur`, which
        // would fire before a click on a result could register.
        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                close();
            }
        });
    }

    function initAllSearch(root) {
        (root || document).querySelectorAll('[data-mn-search]').forEach(initSearch);
    }

    /* ------------------------------------------------------------------ *
     * Boot
     * ------------------------------------------------------------------ */

    document.addEventListener('DOMContentLoaded', function () {
        initTomSelect();
        renderToasts();
        initAllSearch();
    });

    // Expose the pieces that dynamically-rendered pages need to re-run.
    window.MeetingNotes = {
        initTomSelect: initTomSelect,
        renderToasts: renderToasts,
        applyTheme: applyTheme,
        initSearch: initAllSearch,
    };
})();
