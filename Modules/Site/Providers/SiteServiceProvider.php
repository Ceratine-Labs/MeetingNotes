<?php

namespace Modules\Site\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Site (public marketing) module provider.
 *
 * Priority 90 — late, and deliberately so. Its landing and pricing pages read the
 * Billing module's plans, so Billing (25) must have booted first.
 *
 * The module is called Site rather than Public because `public` is a PHP reserved
 * word: `Modules\Public\...` is not a legal namespace.
 */
class SiteServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    /**
     * @param  Application  $app  Typing the parent's untyped parameter is safe:
     *         PHP exempts constructors from signature-variance checks.
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Site');
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath.'/Config/config.php', 'site');
    }

    public function boot(): void
    {
        $this->loadViewsFrom($this->modulePath.'/Resources/views', 'site');
        $this->loadTranslationsFrom($this->modulePath.'/Resources/lang', 'site');

        // Registered at the root, with no prefix — these are the public URLs.
        Route::middleware('web')->group($this->modulePath.'/Routes/web.php');
    }
}
