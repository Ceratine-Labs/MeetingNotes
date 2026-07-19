# MeetingNotes

Paste or upload a meeting transcript; get professional, structured
minutes — the same nine canonical sections every time (see
[docs/PRODUCT_SPEC.md](docs/PRODUCT_SPEC.md)). Edit or regenerate
individual sections with diff-accept, export to DOCX / PDF / Markdown.
Admin controls the LLM provider, prompt templates, and backups.

## Stack

Laravel 13 + PostgreSQL, house HMVC (`Modules/` with `module.json`
auto-discovery — see [CLAUDE.md](CLAUDE.md)), Bootstrap 5 dark, vanilla
JS, mpdf, PHPWord, spatie/laravel-backup.

Every minutes record is a **defined struct**: the LLM fills a validated
JSON schema (`Modules/Minutes/Support/MinutesSchema.php`), typed
`decisions` / `action_items` rows and the canonical HTML render
(`meetings.rendered_html`) are derived from it by the app. The LLM
never produces HTML.

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
# set DB_* (PostgreSQL) and ADMIN_SEED_PASSWORD in .env
createdb meetingnotes
php artisan migrate --force
php artisan seed:master          # seeds run ONCE, tracked in seed_registry
php artisan serve
php artisan queue:work           # REQUIRED — generation runs on the queue
```

Log in as `admin@meetingnotes.local` with `ADMIN_SEED_PASSWORD`, change
the password, then set your LLM API key in **Admin → LLM Settings** and
hit *Test connection*.

## Seed master

`php artisan db:seed` delegates here — nothing ever seeds twice.

```bash
php artisan seed:master            # run pending seeders only
php artisan seed:master --status   # executed vs pending ledger
php artisan seed:master --force=FQCN   # explicit single re-run (audited)
```

A modified already-run seeder is warned about and skipped — write a v2
seeder class instead.

## Scheduler (backups)

Daily backups are configured in **Admin → Backups** and need the
scheduler cron on the host:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Restore procedure: [docs/RESTORE.md](docs/RESTORE.md) — deliberately
manual, no one-click restore.

## Tests

```bash
php artisan test   # runs on in-memory sqlite; all LLM HTTP is faked
```

Deploy samples (nginx, supervisor queue worker): [deploy/](deploy/).
