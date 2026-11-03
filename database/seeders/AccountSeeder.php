<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $password = 'admin123';

        $admin = DB::transaction(function () use ($password) {
            $admins = User::withTrashed()->whereIn('email', ['admin@copp.test', 'admin@copp.production'])->lockForUpdate()->get();
            if ($admins->count() > 1) {
                throw new \RuntimeException('Ada dua akun admin lama dan baru. Periksa keduanya sebelum menjalankan AccountSeeder.');
            }
            $admin = $admins->first() ?? new User;
            if ($admin->exists && ! $admin->isStaff()) {
                throw new \RuntimeException('Email admin digunakan akun yang tidak memiliki role admin.');
            }
            if (User::withTrashed()->where('username', 'didisaidi')->when($admin->exists, fn ($query) => $query->whereKeyNot($admin->id))->exists()) {
                throw new \RuntimeException('Username Didisaidi sudah digunakan akun lain.');
            }
            $oldEmail = $admin->email;
            if (! $admin->trashed()) {
                $admin->forceFill([
                    'name' => 'Didisaidi', 'username' => 'didisaidi', 'email' => 'admin@copp.production',
                    'password' => $password, 'remember_token' => null,
                    'active' => true, 'email_verified_at' => $admin->email_verified_at ?? now(),
                ])->save();
                $admin->assignRole('super-admin');
                DB::table('sessions')->where('user_id', $admin->id)->delete();
                DB::table('password_reset_tokens')->whereIn('email', array_filter([$oldEmail, $admin->email]))->delete();
            }

            // Archive only the original test member; retain financial references.
            $member = User::withTrashed()->where('email', 'member@copp.test')->first();
            if ($member) {
                $member->forceFill(['active' => false, 'remember_token' => null])->save();
                if (! $member->trashed()) {
                    $member->delete();
                }
                DB::table('sessions')->where('user_id', $member->id)->delete();
                DB::table('password_reset_tokens')->where('email', $member->email)->delete();
            }

            return $admin;
        });

        if (! $admin->trashed()) {
            Storage::disk('local')->put('copp-accounts.txt', "COPP Luxury\nNama: Didisaidi\nUsername: Didisaidi\nAdmin: admin@copp.production\nKata sandi: {$password}\n");
            $this->command?->info('Akun admin diperbarui. Catatan: storage/app/private/copp-accounts.txt');
        }
    }
}
