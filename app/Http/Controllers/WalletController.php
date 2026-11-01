<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Services\ReportingService;
use App\Services\ScenarioContext;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __invoke(Request $r, ScenarioContext $context)
    {
        $s = $context->resolve($r);
        $wallet = app(WalletService::class)->get($r->user(), $s);
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'q' => 'nullable|string|max:100', 'sort' => 'nullable|in:asc,desc']);
        $transactions = app(ReportingService::class)->query($s, $d, $r->user()->id)->paginate(15)->withQueryString();
        $stats = app(DashboardService::class)->data($s, $s->start_date->toDateString(), $s->start_date->toDateString(), $r->user()->id)['metrics'];

        return view('wallet', compact('s', 'wallet', 'transactions', 'stats'));
    }
}
