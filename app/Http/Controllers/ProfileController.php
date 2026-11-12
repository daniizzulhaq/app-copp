<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Support\WithdrawalDestination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function index(Request $r)
    {
        return view('profile', ['sessions' => DB::table('sessions')->where('user_id', $r->user()->id)->orderByDesc('last_activity')->get()]);
    }

    public function update(Request $r)
    {
        if (is_string($r->input('username'))) {
            $r->merge(['username' => Str::lower(trim($r->input('username')))]);
        }
        $d = $r->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($r->user()->id)],
            'username' => ['nullable', 'string', 'regex:/\A[a-z0-9_]{3,30}\z/', Rule::unique('users', 'username')->ignore($r->user()->id)],
        ]);
        $u = $r->user();
        $before = $u->only('name', 'email', 'username');
        if ($u->email !== strtolower($d['email'])) {
            $u->email_verified_at = null;
        }
        $u->fill(['name' => $d['name'], 'email' => strtolower($d['email'])]);
        if (array_key_exists('username', $d)) {
            $u->username = $d['username'];
        }
        $u->save();
        app(AuditService::class)->record('profile.updated', $u, $before, $u->only('name', 'email', 'username'));

        return back()->with('status', 'Profil diperbarui.');
    }

    public function bankAccount(Request $r)
    {
        Gate::authorize('transact');
        $r->merge(WithdrawalDestination::normalize($r->only(WithdrawalDestination::FIELDS)));
        $data = $r->validate(WithdrawalDestination::rules($r->user()->bank_name), WithdrawalDestination::messages());
        DB::transaction(function () use ($r, $data) {
            $user = $r->user()->newQuery()->whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $user->forceFill($data)->save();
            app(AuditService::class)->record('profile.bank_account_updated', $user, null, [
                'bank_name' => $data['bank_name'],
                'account_last_four' => substr($data['bank_account_number'], -4),
            ]);
        });

        return back()->with('status', 'Tujuan WD disimpan. Bank atau e-wallet ini akan diisikan pada pengajuan withdrawal berikutnya.');
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()]]);
        $r->user()->update(['password' => $d['password']]);
        $r->user()->forceFill(['remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $r->user()->id)->where('id', '!=', $r->session()->getId())->delete();
        $r->session()->regenerate();
        app(AuditService::class)->record('profile.password_changed', $r->user());

        return back()->with('status', 'Kata sandi diperbarui.');
    }

    public function revoke(Request $r)
    {
        $r->validate(['current_password' => 'required|current_password']);
        DB::table('sessions')->where('user_id', $r->user()->id)->where('id', '!=', $r->session()->getId())->delete();
        $r->user()->forceFill(['remember_token' => Str::random(60)])->save();
        app(AuditService::class)->record('profile.sessions_revoked', $r->user());

        return back()->with('status', 'Sesi perangkat lain dicabut.');
    }
}
