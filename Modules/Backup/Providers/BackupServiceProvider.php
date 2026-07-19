<?php

namespace Modules\Backup\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\SettingsService;

class BackupServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    public function __construct($app)
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

        Route::middleware(['web', 'auth', 'admin'])
            ->prefix('app/admin')
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
