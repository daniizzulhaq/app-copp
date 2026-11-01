<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class ProfileController extends Controller
{
    public function index(Request $r)
    {
        return view('profile', ['sessions' => DB::table('sessions')->where('user_id', $r->user()->id)->orderByDesc('last_activity')->get()]);
    }

    public function update(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($r->user()->id)]]);
        $u = $r->user();
        $before = $u->only('name', 'email');
        if ($u->email !== strtolower($d['email'])) {
            $u->email_verified_at = null;
        }
        $u->fill(['name' => $d['name'], 'email' => strtolower($d['email'])])->save();
        if (! $u->hasVerifiedEmail()) {
            $u->sendEmailVerificationNotification();
        }
        app(AuditService::class)->record('profile.updated', $u, $before, $u->only('name', 'email'));

        return back()->with('status', 'Profil diperbarui.');
    }

    public function bankAccount(Request $r)
    {
        Gate::authorize('transact');
        $r->merge(['bank_account_number' => preg_replace('/[\s-]+/u', '', (string) $r->input('bank_account_number'))]);
        $data = $r->validate([
            'bank_name' => 'required|string|max:100',
            'bank_account_number' => 'required|string|regex:/^[0-9]{5,34}$/',
            'bank_account_holder' => 'required|string|max:150',
        ], ['bank_account_number.regex' => 'Nomor rekening harus berisi 5–34 digit angka.']);
        DB::transaction(function () use ($r, $data) {
            $user = $r->user()->newQuery()->whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $user->forceFill($data)->save();
            app(AuditService::class)->record('profile.bank_account_updated', $user, null, [
                'bank_name' => $data['bank_name'],
                'account_last_four' => substr($data['bank_account_number'], -4),
            ]);
        });

        return back()->with('status', 'Rekening bank disimpan. Rekening ini digunakan untuk pengajuan withdrawal berikutnya.');
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

    public function setupTwoFactor(Request $r)
    {
        $r->validate(['current_password' => 'required|current_password']);
        abort_unless($r->user()->hasRole('super-admin'), 403);
        if ($r->user()->two_factor_confirmed_at) {
            throw ValidationException::withMessages(['code' => '2FA sudah aktif.']);
        }
        $r->session()->put('2fa_setup_secret', (new Google2FA)->generateSecretKey());

        return back()->with('status', 'Masukkan kunci manual ke aplikasi autentikator, lalu verifikasi.');
    }

    public function confirmTwoFactor(Request $r)
    {
        abort_unless($r->user()->hasRole('super-admin'), 403);
        $d = $r->validate(['code' => 'required|digits:6']);
        $secret = $r->session()->get('2fa_setup_secret');
        $counter = $secret ? (new Google2FA)->verifyKeyNewer($secret, $d['code'], 0, 1) : false;
        if (! $counter) {
            throw ValidationException::withMessages(['code' => 'Kode autentikator tidak valid.']);
        }
        $r->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_last_counter' => $counter])->save();
        $r->session()->forget('2fa_setup_secret');
        app(AuditService::class)->record('auth.2fa_enabled', $r->user());

        return back()->with('status', '2FA aktif untuk login berikutnya.');
    }

    public function disableTwoFactor(Request $r)
    {
        $r->validate(['current_password' => 'required|current_password', 'code' => 'required|digits:6']);
        $u = $r->user();
        if (! $u->two_factor_confirmed_at || ! (new Google2FA)->verifyKey($u->two_factor_secret, $r->input('code'), 1)) {
            throw ValidationException::withMessages(['code' => 'Kode autentikator tidak valid.']);
        }
        $u->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_counter' => null])->save();
        app(AuditService::class)->record('auth.2fa_disabled', $u);

        return back()->with('status', '2FA dinonaktifkan.');
    }
}
