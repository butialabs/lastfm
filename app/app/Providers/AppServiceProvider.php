<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Config;
use App\Models\User;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Global data for the public layout (equivalent to the legacy addData()).
        View::composer('layouts.app', function ($view) {
            try {
                $totalUsers = User::count();
                $analyticsScript = Config::getValue('analytics_script');
            } catch (\Throwable) {
                // Database not migrated yet (first boot).
                $totalUsers = 0;
                $analyticsScript = null;
            }

            $view->with('totalUsers', $totalUsers)
                ->with('analyticsScript', $analyticsScript);
        });
    }
}
