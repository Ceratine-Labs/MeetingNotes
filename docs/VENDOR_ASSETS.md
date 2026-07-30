# Vendored front-end assets

## The rule

**Nothing in the page render path may depend on a third-party host.** No
jsDelivr, no unpkg, no Google Fonts, no Bunny Fonts. Every stylesheet, script,
font and image the browser asks for is a file committed in this repository and
served by this application.

Reasons, in order of how much they matter here:

1. **The app must work when the CDN does not.** A jsDelivr outage or a DNS
   hiccup should degrade nothing. Minutes are generated in meetings, often on
   bad connections.
2. **No render-blocking third-party round trips.** Bootstrap-from-CDN is two
   extra DNS lookups and two TLS handshakes before the page can paint.
3. **Reproducible builds.** The exact bytes that were tested are the exact
   bytes that ship. A CDN `@11` range tag silently rolls forward.
4. **Privacy.** No visitor IP is handed to a third party just to load a font.

## No build step

There is deliberately **no bundler** — no Vite, no Tailwind, no `npm install`
on the deploy target. The Laravel scaffold shipped with Vite + Tailwind; both
were removed because nothing referenced them and Tailwind conflicts with the
house Bootstrap 5 standard.

Consequences to be aware of:

- Our own CSS/JS live at `public/css/theme.css` and `public/js/app.js` as plain
  files. Edit and refresh — no watcher, no `npm run dev`.
- Because filenames are not content-hashed, **always reference assets through
  `mn_asset()`** in Blade, never `asset()`. `mn_asset()` appends `?v=<mtime>`
  so a deploy busts browser caches. See
  `Modules/Core/Services/AssetService.php`.
- Deploy is `git pull` + `composer install` + `php artisan migrate`. No Node.

## What is vendored

Everything lives under `public/vendor/<library>/`. Licenses are copied to
`public/vendor/licenses/` — attribution has to travel with the bytes.

| Library | Version | Files kept | Purpose |
|---|---|---|---|
| [@tabler/core](https://github.com/tabler/tabler) | 1.4.0 | `tabler/css/tabler.min.css`, `tabler-marketing.min.css`, `tabler-payments.min.css`, `tabler/js/tabler.min.js`, `tabler/img/payments/*` | UI framework. Bundles Bootstrap 5 — we do **not** ship Bootstrap separately. Marketing CSS is for the public pages; payments CSS + card SVGs for the billing screens. |
| [@tabler/icons-webfont](https://github.com/tabler/tabler-icons) | 3.46.0 | `tabler-icons/tabler-icons.min.css`, `tabler-icons-filled.min.css`, `fonts/*.{woff2,woff,ttf}` | Icon set (`<i class="ti ti-file-text">`). |
| [@fontsource-variable/inter](https://github.com/fontsource/font-files) | 5.3.0 | `inter/inter.css`, `inter/files/*.woff2` | Tabler's font stack asks for "Inter Var" first; without it the UI falls back to system fonts and stops looking like Tabler. |
| [sweetalert2](https://github.com/sweetalert2/sweetalert2) | 11.26.25 | `sweetalert2/sweetalert2.min.{css,js}` | Every alert and confirmation (house rule — no native `alert`/`confirm`). |
| [tom-select](https://github.com/orchidjs/tom-select) | 2.6.2 | `tom-select/tom-select.bootstrap5.min.css`, `tom-select.complete.min.js` | Every searchable / multi-select dropdown (house rule). |
| [apexcharts](https://github.com/apexcharts/apexcharts.js) | 6.6.1 | `apexcharts/apexcharts.min.js`, `apexcharts.css` | Every chart (house rule). Used by the admin analytics and the org usage widget. |
| [intro.js](https://github.com/usablica/intro.js) | 8.5.0 | `introjs/introjs.min.css`, `intro.min.js` | In-app guided tours (house rule). |

### What was deliberately dropped

Trimming matters — the untrimmed set is ~130 MB, mostly icon fonts.

- **Tabler icon `.svg` fonts** — 34 MB each and only needed by IE-era
  browsers. `woff2` is the only format any current browser will pick.
- **Tabler icon weights 200/300** — we use the regular and filled sets only.
- **Tabler RTL stylesheets** — the app is English/Afrikaans, both LTR. Add
  `tabler.rtl.min.css` if that changes.
- **All `.map` files** — source maps of minified vendor code are debugging
  weight we will never use in production.
- **`tabler-theme.min.js`** — Tabler's own theme switcher decides the theme in
  `localStorage` *after* first paint, which flashes the wrong theme. Our server
  stamps `data-bs-theme` during render instead. See `docs/THEMING.md`.
- **Tabler's bundled `dist/libs/*`** — Tabler ships copies of ApexCharts,
  Dropzone, FullCalendar and friends. We vendor the libraries we actually use
  directly, at versions we chose, rather than inheriting Tabler's pins.
- **Inter cyrillic / greek / vietnamese ranges** — ~200 KB of glyphs the UI
  never renders. Only latin + latin-ext are kept.

## Re-fetching or upgrading

Assets come from npm tarballs (not git checkouts) because the tarball is the
published artifact and contains the pre-built `dist/`.

```bash
cd "$(mktemp -d)"

npm pack @tabler/core@1.4.0
npm pack @tabler/icons-webfont@3.46.0
npm pack @fontsource-variable/inter@5.3.0
npm pack sweetalert2@11.26.25
npm pack tom-select@2.6.2
npm pack apexcharts@6.6.1
npm pack intro.js@8.5.0

for f in *.tgz; do mkdir -p "x_${f%.tgz}" && tar xzf "$f" -C "x_${f%.tgz}"; done
# Each tarball extracts to x_<name>/package/ — copy out of package/dist/
# into the matching public/vendor/<library>/ path shown in the table above.
```

`npm` is used purely as a download tool. There is no `package.json` in this
repo and there must not be one — adding it back invites a build step.

### When upgrading Tabler

1. Diff `dist/css/tabler.min.css` for changes to the CSS custom properties our
   theme overrides — specifically the `--tblr-gray-*` ramp and the
   `--tblr-bg-surface*` set. `public/css/theme.css` re-points those; if Tabler
   renames one, the deep dark theme silently reverts on that surface.
2. Check `[data-bs-theme=dark]` is still the theme selector.
3. Load the app in both themes and walk: dashboard, minutes document, billing,
   admin. Those four cover nearly every component we use.
4. Update the version in the table above and in `public/vendor/licenses/`.
