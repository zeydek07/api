<?php

namespace App\Modules\Hr;

use App\Modules\Hr\Shared\Idempotency\EnsureIdempotent;
use App\Modules\Hr\Shared\Idempotency\IdempotentResourceRegistry;
use App\Modules\Hr\Shared\Idempotency\PruneIdempotencyKeys;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('hr.php'), 'hr');
        $this->app->singleton(IdempotentResourceRegistry::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/hr'));

        $this->app['router']->aliasMiddleware('hr.idempotent', EnsureIdempotent::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PruneIdempotencyKeys::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('hr:prune-idempotency')->daily();
        });

        Route::prefix('api/hr')
            ->middleware(['api', 'auth:sanctum', 'active', 'throttle:api'])
            ->group(base_path('routes/hr.php'));
    }
}
