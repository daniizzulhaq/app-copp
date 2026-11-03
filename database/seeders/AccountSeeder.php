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
            $admin = User::withTrashed()->firstOrNew(['email' => 'admin@copp.test']);
            if (! $admin->trashed()) {
                $admin->forceFill([
                    'name' => 'Didisaidi', 'password' => $password, 'remember_token' => null,
                    'active' => true, 'email_verified_at' => $admin->email_verified_at ?? now(),
                ])->save();
                $admin->assignRole('super-admin');
                DB::table('sessions')->where('user_id', $admin->id)->delete();
                DB::table('password_reset_tokens')->where('email', $admin->email)->delete();
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
            Storage::disk('local')->put('copp-accounts.txt', "COPP Luxury\nNama: Didisaidi\nAdmin: admin@copp.test\nKata sandi: {$password}\n");
            $this->command?->info('Akun admin diperbarui. Catatan: storage/app/private/copp-accounts.txt');
        }
    }
}
