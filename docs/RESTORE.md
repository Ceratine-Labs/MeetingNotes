# Restoring a MeetingNotes backup

Deliberately a manual procedure — there is no one-click restore in the
admin UI because restoring a live app's database from a button is a
footgun. Follow these steps on the target machine.

Backups are zip archives created by spatie/laravel-backup, stored on
the `backups` disk (`storage/app/backups/MeetingNotes/`). Each contains:

- `db-dumps/database-meetingnotes.sql` — full PostgreSQL dump
- the contents of `storage/app` (uploaded transcript files)
- `.env` at the path it was backed up from

If `BACKUP_ARCHIVE_PASSWORD` is set in .env the zip is AES-encrypted
with that password.

## Steps

1. **Stop workers** so nothing writes mid-restore:
   ```bash
   php artisan down
   # stop the queue worker (supervisor/systemd or ctrl-c in dev)
   ```

2. **Unpack the archive** (enter the archive password if prompted):
   ```bash
   cd /tmp && unzip /path/to/backup.zip -d mn-restore
   ```

3. **Restore the database:**
   ```bash
   dropdb --if-exists meetingnotes_restore
   createdb meetingnotes_restore
   psql meetingnotes_restore < mn-restore/db-dumps/database-meetingnotes.sql
   ```
   Sanity-check the row counts (`meetings`, `transcripts`, `settings`),
   then either point `.env` at `meetingnotes_restore` or swap names:
   ```sql
   ALTER DATABASE meetingnotes RENAME TO meetingnotes_old;
   ALTER DATABASE meetingnotes_restore RENAME TO meetingnotes;
   ```

4. **Restore uploaded files** — copy the archived `storage/app`
   contents over the app's `storage/app` (do NOT copy `backups/`
   back into itself).

5. **Keys:** `APP_KEY` in the restored `.env` must match the database
   you restored — settings values (LLM API keys) are encrypted with it.
   If you changed APP_KEY since the backup, restore the old key or
   re-enter the API keys in Admin → LLM Settings.

6. **Verify and reopen:**
   ```bash
   php artisan migrate --force   # no-op if schema matches
   php artisan seed:master --status
   php artisan up
   ```
   Log in, open a minutes record, run a test generation.
