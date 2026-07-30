<?php

namespace Modules\Search\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Search\Services\SearchIndexer;
use Modules\Search\Services\SearchService;

/**
 * Search module provider.
 *
 * Priority 35 — after Minutes (30), because the indexer reads Minutes models and the
 * generation pipeline calls into the indexer.
 */
class SearchServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    /**
     * @param  Application  $app  Typing the parent's untyped parameter is safe:
     *         PHP exempts constructors from signature-variance checks.
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('Modules/Search');
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath.'/Config/config.php', 'search');

        $this->app->singleton(SearchIndexer::class);
        $this->app->singleton(SearchService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath.'/Database/Migrations');
        $this->loadViewsFrom($this->modulePath.'/Resources/views', 'search');
        $this->loadTranslationsFrom($this->modulePath.'/Resources/lang', 'search');

        Route::middleware('web')->group($this->modulePath.'/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Search\Console\ReindexSearchCommand::class,
            ]);
        }
    }
}
