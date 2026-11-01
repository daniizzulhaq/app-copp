<?php

namespace App\Providers;

use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage', fn (User $u) => $u->active && $u->hasRole('super-admin'));
        Gate::define('view-reports', fn (User $u) => $u->active && $u->isStaff());
        Gate::define('transact', fn (User $u) => $u->active && $u->hasRole('member') && ! $u->is_cohort);
        View::composer(['layouts.app', 'dashboard', 'member.dashboard', 'reports.index'], function ($view) {
            $query = SimulationScenario::where('status', '!=', 'archived');
            if (auth()->check() && ! auth()->user()->isStaff() && SystemSetting::whereKey('local_workspace')->exists()) {
                $query->where('mode', 'local');
            }
            $view->with('scenarioList', $query->orderBy('id')->get());
        });
    }
}
