<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use App\Models\TruckRoute;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Safe old_text() / old_list() helpers for forms.
        require_once app_path('Support/helpers.php');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pagination links use our own simple view (resources/views/vendor/pagination/ecowatch.blade.php).
        Paginator::defaultView('vendor.pagination.ecowatch');
        Schema::defaultStringLength(191);

        // Every CGSO page shows a clear message when a route has lost its truck or barangay.
        View::composer('layouts.admin', function ($view) {
            $view->with('routesNeedingReassignment', TruckRoute::needingReassignment()->orderBy('name')->get());
        });
    }
}
