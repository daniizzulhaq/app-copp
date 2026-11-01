<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ReferralService;
use App\Services\ReportingService;
use App\Services\ScenarioContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class MemberController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('view-reports');
        $r->validate(['q' => 'nullable|string|max:100', 'active' => 'nullable|in:0,1', 'sort' => 'nullable|in:asc,desc']);
        $members = User::role('member')->where('is_cohort', false)->when($r->filled('active'), fn ($q) => $q->where('active', $r->active))
            ->when($r->q, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('email', 'like', '%'.$r->q.'%')->orWhere('member_code', 'like', '%'.$r->q.'%')))
            ->withCount('investments', 'referrals')->orderBy('id', $r->sort === 'asc' ? 'asc' : 'desc')->paginate(15)->withQueryString();

        return view('members.index', compact('members'));
    }

    public function create()
    {
        Gate::authorize('manage');

        return view('members.form', ['member' => new User]);
    }

    public function store(Request $r)
    {
        Gate::authorize('manage');
        $d = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()], 'referral' => 'nullable|string|max:12']);
        DB::transaction(function () use ($d) {
            $u = User::create(['name' => $d['name'], 'email' => strtolower($d['email']), 'password' => $d['password']]);
            $u->forceFill(['email_verified_at' => now()])->save();
            $u->assignRole('member');
            app(ReferralService::class)->attach($u, $d['referral'] ?? null);
            app(AuditService::class)->record('member.created', $u);
        });

        return redirect('/members')->with('status', 'Member dummy ditambahkan.');
    }

    public function show(Request $r, User $member, ScenarioContext $context)
    {
        Gate::authorize('view-reports');
        Gate::authorize('view', $member);
        $s = $context->resolve($r);
        $investments = $member->investments()->where('simulation_scenario_id', $s->id)->withSum('obligations', 'paid_amount')->latest()->paginate(10);
        $referrals = $member->referrals()->with('referred')->get();
        $activity = AuditLog::where('actor_id', $member->id)->latest('id')->limit(10)->get();
        $transactions = app(ReportingService::class)->query($s, [], $member->id)->limit(10)->get();

        return view('members.show', compact('member', 's', 'investments', 'referrals', 'activity', 'transactions'));
    }

    public function edit(User $member)
    {
        Gate::authorize('update', $member);

        return view('members.form', compact('member'));
    }

    public function update(Request $r, User $member)
    {
        Gate::authorize('update', $member);
        $d = $r->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($member->id)], 'active' => 'required|boolean']);
        DB::transaction(function () use ($member, $d) {
            $before = $member->only('name', 'email', 'active');
            $member->fill(['name' => $d['name'], 'email' => strtolower($d['email'])]);
            $member->active = (bool) $d['active'];
            $member->save();
            if (! $member->active) {
                DB::table('sessions')->where('user_id', $member->id)->delete();
            } app(AuditService::class)->record('member.updated', $member, $before, $member->only('name', 'email', 'active'));
        });

        return redirect('/members/'.$member->id)->with('status', 'Member diperbarui.');
    }

    public function destroy(User $member)
    {
        Gate::authorize('update', $member);
        DB::transaction(function () use ($member) {
            $member->active = false;
            $member->save();
            DB::table('sessions')->where('user_id', $member->id)->delete();
            app(AuditService::class)->record('member.soft_deleted', $member);
            $member->delete();
        });

        return redirect('/members')->with('status', 'Member diarsipkan; seluruh histori transaksi tersimpan.');
    }
}
