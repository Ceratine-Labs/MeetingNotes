# MeetingNotes

@CERATINE_API_INSTRUCTIONS.md

## Stack — standard

- **Laravel** (latest stable)
- **PostgreSQL**
- **Vanilla JavaScript** — no jQuery, no React, no Vue, no Alpine
- **Bootstrap 5** (dark theme baseline)
- **Apex Charts** — every chart
- **Tom Select** — every searchable / multi-select dropdown
- **IntroJS** — in-app guided tours
- **Reverb** — websocket / realtime
- **SweetAlert2** — every alert and confirmation. No native `alert()`,
  `confirm()`, or `prompt()`.

## Modular architecture

This project follows the in-house **HMVC modular** pattern. Every
feature lives in a self-contained module under `/Modules/<ModuleName>/`
with its own models, controllers, services, views, migrations, and
service provider. Modules are discovered via each module's
`module.json` and registered automatically at boot.

### Standard module layout

```
/Modules/<ModuleName>/
├── Config/config.php
├── Database/
│   ├── Migrations/        # v1__NN_module_tables.php (NN = order)
│   └── Seeders/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   ├── Middleware/
│   └── Requests/
├── Models/                # extend BaseModel (company-scoped)
│                          # or BaseModelWithoutCompany (system)
├── Services/              # all business logic lives here
├── Events/ & Listeners/
├── Providers/<ModuleName>ServiceProvider.php
├── Routes/web.php
├── Routes/api.php
├── Resources/
│   ├── views/             # referenced as `view('module::path.name')`
│   └── lang/{en,afr}/
└── module.json
```

### Naming + conventions

- Module names: PascalCase (e.g. `Sales`, `StockLedger`).
- Namespaces: `Modules\<ModuleName>\<Subdir>`.
- Models extend `BaseModel` (auto company-scoped) or
  `BaseModelWithoutCompany` (truly global).
- Services hold the business logic — controllers stay thin.
- Views are referenced via the module alias:
  `view('sales::orders.show', [...])`.
- Routes are registered in the module's ServiceProvider's `boot()`
  via `Route::prefix('app')->middleware('web')->group(...)` for web
  and `prefix('api')->middleware('api')` for API.

### Hard rules

1. **No DB-level foreign keys.** Referential integrity is enforced
   at the application layer (Eloquent relationships, FormRequests,
   service-layer validation). Keeps migration ordering simple.
2. **UUID primary keys only.** Never auto-increment.
3. **Cross-module imports are allowed** via full namespaces — but
   prefer firing events between modules over direct calls when the
   coupling is non-trivial.
4. **Company scoping is automatic.** Don't add manual `where company_id`
   clauses to BaseModel queries — the global scope handles it.
5. **One migration per version per module.**
   `v1__NN_module_tables.php` where NN is the order. v2 is a follow-up.
6. **Sidebar navigation is database-driven.** Each module has a
   `MenuSeeder.php` that calls `MenuService::seed(...)`. Never edit
   blade sidebar files directly.

### Creating a new module

1. Create `/Modules/NewModule/` (copy an existing module as a starter).
2. Create `module.json` with name, alias, version, providers, requires.
3. Create `Providers/NewModuleServiceProvider.php` with `register()`
   and `boot()`.
4. Create `Config/config.php`, `Database/Migrations/v1__NN_module_tables.php`,
   `Models/`, `Services/`, `Http/Controllers/`, `Http/Requests/`,
   `Routes/web.php`, `Routes/api.php`, `Resources/views/`, `Resources/lang/`.
5. Create `Database/Seeders/MenuSeeder.php` with the navigation entries.
6. Write tests (Unit, Feature, E2E) before marking the module "done".

For the canonical reference, layout examples, and working counterparts
of every file above, see the in-house reference repo on the local
machine (path provided separately — not embedded in generated docs).

## Migrations

- Major / minor versioning starts at **v1**. This project is at v1
  unless this file says otherwise.
- Each schema change after the v1 baseline bumps the minor version
  (v1.1, v1.2, ...). Major bumps are reserved for breaking rewrites.

## Design patterns + component conventions

Follow the in-house style guide for controllers, services, blade
structure, dark-theme styling, JS patterns, datagrid usage, SweetAlert
wrapping, and routing, unless the project-specific section below
overrides. (The reference repo path is provided separately and not
embedded here.)

## Project-specific notes

Stack: Laravel latest + PostgreSQL, house standard UI (Bootstrap 5 dark baseline, vanilla JS, SweetAlert2, Tom Select; ApexCharts if charts appear). Bespoke Modules/ HMVC per house pattern: module.json per module, auto-discovery at boot, UUID PKs, NO DB-level foreign keys, one migration per version per module (v1__NN_*), DB-driven sidebar via MenuSeeder/MenuService, services hold business logic. Single-tenant: BaseModel = UUID keys, no company scoping. Seeds run ONLY through seed master (php artisan seed:master) recording into seed_registry — never re-run. LLM layer admin-configurable (Anthropic default, OpenAI, OpenAI-compatible base URL). Minutes stored as defined SQL struct (meetings + decisions + action_items rows) with canonical rendered_html per record. PDF via mpdf, backups via spatie/laravel-backup + admin UI. Source spec: docs/PRODUCT_SPEC.md. Build plan: BUILD_PLAN.md.
