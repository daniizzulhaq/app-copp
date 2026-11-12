<?php

namespace App\Providers;

use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WithdrawalRequest;
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
        View::composer('member.withdrawal-ticker', function ($view) {
            $viewer = auth()->user();
            $view->with('successfulWithdrawals', collect());
            if (! $viewer || $viewer->isStaff()) {
                return;
            }

            $scenarioId = SystemSetting::whereKey('local_workspace')->value('value')
                ?? ($view->getData()['s']->id ?? session('scenario_id'))
                ?? SystemSetting::whereKey('default_scenario')->value('value');
            $withdrawals = WithdrawalRequest::query()
                ->select(['id', 'user_id', 'amount', 'reviewed_at'])
                ->with('user:id,name')
                ->where('simulation_scenario_id', $scenarioId)
                ->whereHas('scenario', fn ($query) => $query->where('mode', 'local')->where('status', '!=', 'archived'))
                ->where('status', 'approved')->whereNotNull('reviewed_at')
                ->where('user_id', '!=', $viewer->id)
                ->whereHas('user', fn ($query) => $query->whereNull('deleted_at')
                    ->where('active', true)->where('is_cohort', false)
                    ->whereHas('roles', fn ($roles) => $roles->where('name', 'member'))
                    ->whereDoesntHave('roles', fn ($roles) => $roles->where('name', 'super-admin')))
                ->orderByDesc('reviewed_at')->orderByDesc('id')->limit(10)->get()
                ->map(fn (WithdrawalRequest $withdrawal) => [
                    'name' => mb_substr(trim($withdrawal->user->name), 0, 2).'***',
                    'amount' => $withdrawal->amount,
                    'completed_at' => $withdrawal->reviewed_at->timezone('Asia/Jakarta'),
                ]);

            $view->with('successfulWithdrawals', $withdrawals);
        });
    }
}
