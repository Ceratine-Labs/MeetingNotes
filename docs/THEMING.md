# Theming

Light default, deep dark, no flash of the wrong theme.

## How a theme is chosen

**The server decides, during render.** Blade stamps the result onto
`<html data-bs-theme="...">`, so the very first paint is already correct.

```
ThemeService::current($request)
  1. authenticated user's `users.theme` column   → follows them across devices
  2. the `mn_theme` cookie                       → guests, and not-yet-synced users
  3. 'light'                                     → the product default
```

`prefers-color-scheme` is deliberately **not** in that list. PHP cannot read a media
query, and honouring it would mean deciding in JS after paint. Instead the layout
carries a few lines of inline script that consult it **only when no preference exists at
all**, correct the attribute, and drop the cookie — so every subsequent request is
server-decided with no JS involved.

This is also why Tabler's own `tabler-theme.js` is **not** vendored: it decides in
`localStorage` after the document loads, which flashes the wrong theme on every
navigation.

## Switching

The toggle (`core::layouts.partials.theme-toggle`) has both buttons in the DOM at all
times; CSS decides which is visible (`.hide-theme-dark` / `.hide-theme-light`), so
switching needs no re-render and no icon swap in JS.

Click → delegated `[data-mn-theme-toggle]` handler in `public/js/app.js`:

1. flips `data-bs-theme` on `<html>` — instant, no reload
2. writes the `mn_theme` cookie so the next request renders correctly
3. for a signed-in user, `POST /app/theme` to save it against their row

Step 3 is fire-and-forget. The cookie already covers this browser, so a failed save is
cosmetic and never surfaces an error.

## The deep dark palette

Tabler's stock dark bottoms out at `#111827`, which reads as washed out. `public/css/theme.css`
re-points the dark end of Tabler's gray ramp, and because Tabler derives surfaces,
borders and muted text from that ramp, the change propagates through components we
never touch.

Surface ladder, darkest to lightest:

| Token | Value | Used for |
|---|---|---|
| `--tblr-body-bg` | `#04060a` | page background |
| `--tblr-bg-surface` | `#0b0f16` | cards, navbar, modals, dropdowns |
| `--tblr-bg-surface-tertiary` | `#111722` | card headers, hovers |
| `--tblr-border-color` | `#1a2130` | borders |
| `--tblr-body-color` | `#cbd5e1` | body text |

Light is left almost entirely alone — Tabler's light theme is already the look we want.
Only the page background is softened to `#f4f6fa` so white cards read as raised.

## Rules for changing the theme

**Express changes as `--tblr-*` variable overrides inside the theme blocks.** Do not
restyle Tabler components directly:

```css
/* NO — breaks the other theme and every future Tabler upgrade */
.card { background: #0b0f16; }

/* YES */
[data-bs-theme='dark'] { --tblr-bg-surface: #0b0f16; }
```

Our own tokens are prefixed `--mn-*` so they can never collide with a Tabler variable in
a later release.

Third-party widgets (SweetAlert2, Tom Select) are bound to Tabler variables at the
bottom of `theme.css`, so they follow the active theme without a per-theme JS config
object at every call site.

## Layouts

| Layout | For | Notes |
|---|---|---|
| `core::layouts.app` | signed-in customer app | sidebar from `MenuService`, org switcher, usage meter |
| `core::layouts.guest` | login, register, reset, invites | single centred card |
| `core::layouts.marketing` | public pages | horizontal nav, indexable, loads Tabler's marketing CSS |
| `admin::layouts.app` | back office | dark sidebar as a deliberate "you are in the back office" signal |

All four share `core::layouts.partials.head` and `foot`, so an asset can never drift
between shells. `$theme` is injected into all four by a view composer in
`CoreServiceProvider::composeLayouts()` — a new module can `@extends` a shell and it
just works, with no boilerplate to forget.

### Per-page opt-in bundles

Declare in the page with an explicit truthy value, because `@hasSection` only sees
sections the child view registered:

```blade
@section('charts', true)            {{-- ApexCharts CSS + JS --}}
@section('tour', true)             {{-- IntroJS --}}
@section('tabler_marketing', true) {{-- landing/pricing hero + typography --}}
@section('tabler_payments', true)  {{-- card-brand logos --}}
```

## Assets

No bundler. `public/css/theme.css` and `public/js/app.js` are plain files — edit and
refresh. Because filenames are not content-hashed, **always reference assets through
`mn_asset()`**, never `asset()`: it appends `?v=<mtime>` so a deploy busts browser
caches. See `docs/VENDOR_ASSETS.md` for the full reasoning and the vendored library
list.

## JS conventions

`public/js/app.js` is vanilla, delegated from `document`, and loaded as a classic
script. Everything is opt-in from markup, so a Blade partial swapped in later is wired
up without re-running any init:

| Attribute | Effect |
|---|---|
| `data-confirm="…"` | SweetAlert2 confirmation before submit/navigate (house rule: no native `confirm`). Plus `data-confirm-title`, `-button`, `-danger`, `-icon`. |
| `data-mn-theme-toggle="dark\|light"` | theme switch |
| `data-tom-select` | Tom Select on a `<select>`; `data-tom-create` to allow new options |
| `data-mn-toast="success\|error\|info\|warning"` | rendered as a toast on load (used by the flash partial) |
| `data-mn-copy="#selector"` | copy to clipboard |
| `data-mn-wordcount="#target"` | live word count, used by the transcript paste box |

Re-run after injecting markup: `window.MeetingNotes.initTomSelect()` /
`.renderToasts()`.
