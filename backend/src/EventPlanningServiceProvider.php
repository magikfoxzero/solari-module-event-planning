<?php

namespace NewSolari\EventPlanning;

use Illuminate\Support\ServiceProvider;
use NewSolari\Core\Module\ModuleRegistry;

class EventPlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventPlanningModule::class);
    }

    public function boot(): void
    {
        // Register with module system
        if ($this->app->bound(ModuleRegistry::class)) {
            app(ModuleRegistry::class)->register(app(EventPlanningModule::class));
        }

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Load migrations (if any module-specific migrations exist)
        if (is_dir(__DIR__ . '/../database/migrations')) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
    }
}
