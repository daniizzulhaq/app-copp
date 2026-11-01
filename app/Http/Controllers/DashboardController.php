<?php

namespace App\Http\Controllers;

use App\Models\RoiPackage;
use App\Services\DailyRoiClaimService;
use App\Services\DashboardService;
use App\Services\ProductTurnoverService;
use App\Services\ScenarioContext;
use App\Services\WalletService;
use App\Services\WorkspaceClock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function __invoke(Request $r, ScenarioContext $context, DashboardService $dashboard)
    {
        $s = $context->resolve($r);
        $claimScenario = $s;
        $roiClaims = $s->isLocal() && ! $r->user()->isStaff()
            ? app(DailyRoiClaimService::class)->forMember($s, $r->user()) : collect();
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'range' => 'nullable|in:day,week,month,all,custom']);
        $end = app(WorkspaceClock::class)->date($s);
        $range = $d['range'] ?? 'month';
        $to = $d['to'] ?? $end->toDateString();
        $from = $d['from'] ?? match ($range) {
            'day' => $to,'week' => Carbon::parse($to)->subDays(6)->toDateString(),'all' => $s->start_date->toDateString(),default => Carbon::parse($to)->subDays(29)->toDateString()
        };
        if (Carbon::parse($from)->greaterThan(Carbon::parse($to)) || abs(Carbon::parse($from)->diffInDays(Carbon::parse($to))) > 366) {
            throw ValidationException::withMessages(['to' => 'Tanggal akhir harus setelah tanggal awal, dengan rentang maksimum 366 hari.']);
        }
        $staff = $r->user()->isStaff();
        $data = $dashboard->data($s, $from, $to, $staff ? null : $r->user()->id);
        $wallet = $staff ? null : app(WalletService::class)->get($r->user(), $s);

        if (! $staff) {
            $featuredPackages = RoiPackage::where('active', true)->orderBy('minimum_amount')->limit(2)->get();
            $memberInvestments = $r->user()->investments()->where('simulation_scenario_id', $s->id)
                ->withSum('obligations', 'paid_amount')->latest()->limit(2)->get();

            return view('member.dashboard', array_merge($data, compact('s', 'from', 'to', 'range', 'staff', 'wallet', 'featuredPackages', 'memberInvestments', 'claimScenario', 'roiClaims')));
        }

        $turnover = $s->isLocal() ? app(ProductTurnoverService::class)->report($s, $from, $to) : null;

        return view('dashboard', array_merge($data, compact('s', 'from', 'to', 'range', 'staff', 'wallet', 'turnover')));
    }
}
