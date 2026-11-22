<?php

declare(strict_types=1);

namespace Modules\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base provider for a module in the modular monolith.
 *
 * Each module lives in modules/<Name> with its own routes, migrations, services,
 * policies and events. This base class wires the conventional folders so that a
 * module is self-contained and can later be extracted into its own service.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** Folder of the module, e.g. modules/Tenancy. */
    abstract protected function modulePath(): string;

    public function boot(): void
    {
        $base = $this->modulePath();

        if (is_dir($base.'/database/migrations')) {
            $this->loadMigrationsFrom($base.'/database/migrations');
        }

        if (is_file($base.'/routes/api.php')) {
            Route::middleware('api')->prefix('v1')->group($base.'/routes/api.php');
        }

        if (is_file($base.'/routes/root.php')) {
            Route::group([], $base.'/routes/root.php');
        }

        $this->bootModule();
    }

    /** Hook for module-specific boot logic (policies, listeners, macros). */
    protected function bootModule(): void {}
}
