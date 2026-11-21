<?php

namespace App\Modules\Hr;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('hr.php'), 'hr');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/hr'));

        Route::prefix('api/hr')
            ->middleware(['api', 'auth:sanctum', 'active', 'throttle:api'])
            ->group(base_path('routes/hr.php'));
    }
}
