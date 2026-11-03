<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\SimulationNotification;
use App\Services\AuditService;
use App\Services\ReferralService;
use App\Services\TemporaryPasswordResetService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|string|max:255', 'password' => 'required|string']);
        $identifier = Str::lower(trim($d['email']));
        $field = str_contains($identifier, '@') ? 'email' : 'username';
        $u = User::whereRaw('LOWER('.$field.') = ?', [$identifier])->where('active', true)->first();
        $key = 'login:'.($u ? 'user:'.$u->id : $identifier).'|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Terlalu banyak percobaan. Coba dalam '.RateLimiter::availableIn($key).' detik.']);
        }
        RateLimiter::hit($key, 60);
        if (! $u || ! Hash::check($d['password'], $u->password)) {
            throw ValidationException::withMessages(['email' => 'Email, username, atau kata sandi tidak sesuai.']);
        }
        Auth::login($u, $r->boolean('remember'));
        $r->session()->regenerate();
        RateLimiter::clear($key);
        app(AuditService::class)->record('auth.login', $u);

        return redirect()->intended('/dashboard');
    }

    public function register(Request $r)
    {
        if (is_string($r->input('username'))) {
            $r->merge(['username' => Str::lower(trim($r->input('username')))]);
        }
        $d = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'confirmed', PasswordRule::min(10)->mixedCase()->numbers()],
            'username' => ['nullable', 'string', 'regex:/\A[a-z0-9_]{3,30}\z/', 'unique:users,username'],
            'referral' => 'nullable|string|max:12']);
        $u = DB::transaction(function () use ($d) {
            $u = User::create(['name' => $d['name'], 'username' => $d['username'] ?? null, 'email' => Str::lower($d['email']), 'password' => $d['password']]);
            $u->assignRole('member');
            app(ReferralService::class)->attach($u, $d['referral'] ?? null);
            $u->notify(new SimulationNotification('Selamat datang di COPP Luxury', 'Akun Anda sudah aktif dan bisa langsung digunakan.'));
            app(AuditService::class)->record('auth.register', $u);

            return $u;
        });
        event(new Registered($u));
        Auth::login($u);
        $r->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $r)
    {
        app(AuditService::class)->record('auth.logout', $r->user());
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/');
    }

    public function forgotForm(Request $r, TemporaryPasswordResetService $temporary)
    {
        return view('auth.forgot', ['temporaryReset' => $temporary->enabled()]);
    }

    public function forgot(Request $r, TemporaryPasswordResetService $temporary)
    {
        $data = $r->validate(['email' => 'required|email|max:255']);
        if ($temporary->enabled()) {
            $temporary->begin($r, $data['email']);

            return redirect()->route('password.temporary');
        }
        Password::sendResetLink($r->only('email'));

        return back()->with('status', 'Jika email terdaftar, tautan reset telah dikirim.');
    }

    public function temporaryResetForm(Request $r, TemporaryPasswordResetService $temporary)
    {
        $account = $temporary->account($r);
        if (! $account) {
            return redirect()->route('password.request')->withErrors(['email' => 'Masukkan email terlebih dahulu untuk membuat password baru.']);
        }

        return response()->view('auth.reset-temporary', ['accountEmail' => $account->email])
            ->header('Cache-Control', 'private, no-store');
    }

    public function temporaryReset(Request $r, TemporaryPasswordResetService $temporary)
    {
        if (! $temporary->account($r)) {
            return redirect()->route('password.request')->withErrors(['email' => 'Sesi reset sudah berakhir. Masukkan email kembali.']);
        }
        $data = $r->validate(['password' => ['required', 'confirmed', PasswordRule::min(10)->mixedCase()->numbers()]]);
        $temporary->reset($r, $data['password']);

        return redirect()->route('login')->with('status', 'Password berhasil diperbarui. Silakan masuk dengan password baru.');
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
}
