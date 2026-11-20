<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MemberBonus;
use App\Models\Referral;
use App\Services\MemberBonusService;
use App\Services\ScenarioContext;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function notifications(Request $r)
    {
        $notifications = $r->user()->notifications()->paginate(20);

        return view('notifications', compact('notifications'));
    }

    public function read(Request $r, string $notification)
    {
        $n = $r->user()->notifications()->whereKey($notification)->firstOrFail();
        $n->markAsRead();

        return back()->with('status', 'Notifikasi ditandai dibaca.');
    }

    public function readAll(Request $r)
    {
        $r->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'Semua notifikasi ditandai dibaca.');
    }

    public function referrals(Request $r, ScenarioContext $context)
    {
        $staff = $r->user()->isStaff();
        $referrals = Referral::with('referrer', 'referred')->when(! $staff, fn ($q) => $q->where('referrer_id', $r->user()->id))->latest()->paginate(20);
        $total = Referral::when(! $staff, fn ($q) => $q->where('referrer_id', $r->user()->id))->count();
        $s = $context->resolve($r);
        $progress = app(MemberBonusService::class)->progress($r->user(), $s);
        $bonuses = MemberBonus::where('user_id', $r->user()->id)->where('simulation_scenario_id', $s->id)->latest()->limit(20)->get();
        $bonusTotal = (int) MemberBonus::where('user_id', $r->user()->id)->where('simulation_scenario_id', $s->id)->sum('amount');

        return view('referrals', compact('s', 'referrals', 'total', 'staff', 'progress', 'bonuses', 'bonusTotal'));
    }

    public function activity(Request $r)
    {
        $logs = AuditLog::where('actor_id', $r->user()->id)->latest('id')->paginate(20);

        return view('activity', compact('logs'));
    }
}
