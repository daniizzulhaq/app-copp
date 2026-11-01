<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\SimulationNotification;
use App\Services\AuditService;
use App\Services\ReferralService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email|max:255', 'password' => 'required|string', 'code' => 'nullable|digits:6']);
        $key = 'login:'.Str::lower($d['email']).'|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Terlalu banyak percobaan. Coba dalam '.RateLimiter::availableIn($key).' detik.']);
        }
        RateLimiter::hit($key, 60);
        $u = User::where('email', Str::lower($d['email']))->where('active', true)->first();
        if (! $u || ! Hash::check($d['password'], $u->password)) {
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak sesuai.']);
        }
        if ($u->two_factor_confirmed_at) {
            DB::transaction(function () use ($u, $d) {
                $locked = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
                $counter = (new Google2FA)->verifyKeyNewer($locked->two_factor_secret, $d['code'] ?? '', $locked->two_factor_last_counter ?? 0, 1);
                if ($counter === false) {
                    throw ValidationException::withMessages(['code' => 'Kode autentikator salah atau sudah digunakan.']);
                }
                $locked->forceFill(['two_factor_last_counter' => $counter])->save();
            });
        }
        Auth::login($u, $r->boolean('remember'));
        $r->session()->regenerate();
        RateLimiter::clear($key);
        app(AuditService::class)->record('auth.login', $u);

        return redirect()->intended('/dashboard');
    }

    public function register(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'confirmed', PasswordRule::min(10)->mixedCase()->numbers()],
            'referral' => 'nullable|string|max:12', 'simulation_consent' => 'accepted']);
        $u = DB::transaction(function () use ($d) {
            $u = User::create(['name' => $d['name'], 'email' => Str::lower($d['email']), 'password' => $d['password']]);
            $u->assignRole('member');
            app(ReferralService::class)->attach($u, $d['referral'] ?? null);
            $u->notify(new SimulationNotification('Selamat datang di COPP Luxury', 'Akun Anda hanya digunakan untuk simulasi.'));
            app(AuditService::class)->record('auth.register', $u);

            return $u;
        });
        event(new Registered($u));
        Auth::login($u);
        $r->session()->regenerate();

        return redirect()->route('verification.notice');
    }

    public function logout(Request $r)
    {
        app(AuditService::class)->record('auth.logout', $r->user());
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/');
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return back()->with('status', 'Jika email terdaftar, tautan reset telah dikirim. Mode lokal: periksa storage/logs/laravel.log.');
    }

    public function reset(Request $r)
    {
        $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->mixedCase()->numbers()]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function ($u, $password) {
            $u->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $u->id)->delete();
            event(new PasswordReset($u));
            app(AuditService::class)->record('auth.password_reset', $u);
        });

        return $status === Password::PASSWORD_RESET ? redirect('/login')->with('status', 'Kata sandi berhasil direset.') : back()->withErrors(['email' => __($status)]);
    }

    public function verify(EmailVerificationRequest $r)
    {
        $r->fulfill();

        return redirect('/dashboard')->with('status', 'Email terverifikasi.');
    }

    public function resend(Request $r)
    {
        if (! $r->user()->hasVerifiedEmail()) {
            $r->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'Tautan verifikasi dikirim. Mode lokal: periksa storage/logs/laravel.log.');
    }
}
