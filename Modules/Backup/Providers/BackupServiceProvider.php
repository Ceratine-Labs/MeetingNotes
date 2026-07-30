<?php

namespace Modules\Backup\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\SettingsService;

class BackupServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    /**
     * @param  Application  $app  Typing the parent's untyped parameter is safe:
     *         PHP exempts constructors from signature-variance checks.
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Backup');
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadViewsFrom($this->modulePath . '/Resources/views', 'backup');

        // These screens are back-office tools, so they sit behind the Admin
        // module's `admin.auth` guard (the `admins` table), NOT the legacy
        // `admin` user-role alias they used before the SaaS conversion. The
        // prefix moved from /app/admin to /admin to match, and the Admin
        // sidebar links to them by route name.
        Route::middleware(['web', 'admin.auth'])
            ->prefix('admin')
            ->group($this->modulePath . '/Routes/web.php');

        $this->applySettings();
        $this->scheduleBackups();
    }

    /**
     * Push admin-configured values into spatie config at boot. Guarded:
     * during migrate/install the settings table may not exist yet.
     */
    protected function applySettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $settings = app(SettingsService::class);

            if ($email = $settings->get('backup.notify_email')) {
                config(['backup.notifications.mail.to' => $email]);
            }
        } catch (\Throwable) {
            // DB not reachable (e.g. during install) — run with defaults.
        }
    }

    protected function scheduleBackups(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            try {
                if (! Schema::hasTable('settings')) {
                    return;
                }

                $settings = app(SettingsService::class);

                if ($settings->get('backup.schedule_enabled', '0') !== '1') {
                    return;
                }

                $time = $settings->get('backup.daily_time', '02:00');

                $schedule->command('backup:clean')->dailyAt($time);
                $schedule->command('backup:run')->dailyAt(
                    \Illuminate\Support\Carbon::parse($time)->addMinutes(15)->format('H:i')
                );
            } catch (\Throwable) {
                // Settings unavailable — no schedule.
            }
        });
    }
}
