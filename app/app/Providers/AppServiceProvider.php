<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Config;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

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
