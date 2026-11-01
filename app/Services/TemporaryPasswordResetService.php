<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TemporaryPasswordResetService
{
    private const SESSION_KEY = 'temporary_password_reset';

    public function enabled(): bool
    {
        return (bool) config('nexavest.temporary_password_reset_enabled', true);
    }

    public function begin(Request $request, string $email): void
    {
        abort_unless($this->enabled(), 404);
        $request->session()->forget(self::SESSION_KEY);
        $user = User::where('email', Str::lower(trim($email)))->where('active', true)->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'Email tidak ditemukan atau akun tidak aktif.']);
        }
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->id, 'email' => $user->email,
            'password_hash' => hash('sha256', $user->getRawOriginal('password')),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ]);
    }

    public function account(Request $request, bool $lock = false): ?User
    {
        abort_unless($this->enabled(), 404);
        $grant = $request->session()->get(self::SESSION_KEY);
        if (! is_array($grant) || ($grant['expires_at'] ?? 0) <= now()->timestamp) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }
        $user = User::whereKey($grant['user_id'] ?? null)->where('active', true)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $user || $user->email !== ($grant['email'] ?? null)
            || ! hash_equals(hash('sha256', $user->getRawOriginal('password')), $grant['password_hash'] ?? '')) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $user;
    }

    public function reset(Request $request, string $password): void
    {
        abort_unless($this->enabled(), 404);
        DB::transaction(function () use ($request, $password) {
            $user = $this->account($request, true);
            if (! $user) {
                throw ValidationException::withMessages(['email' => 'Sesi reset sudah berakhir. Masukkan email kembali.']);
            }
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            Password::deleteToken($user);
            event(new PasswordReset($user));
            app(AuditService::class)->record('auth.password_reset', $user, null, ['method' => 'temporary_email_lookup']);
        }, 5);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
