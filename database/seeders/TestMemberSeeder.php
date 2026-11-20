<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestMemberSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        DB::transaction(function () {
            $member = User::withTrashed()->where('email', 'tester@copp.test')->lockForUpdate()->first() ?? new User;
            if ($member->exists && $member->isStaff()) {
                throw new \RuntimeException('Email member testing digunakan akun admin.');
            }
            if (User::withTrashed()->where('username', 'membertest')
                ->when($member->exists, fn ($query) => $query->whereKeyNot($member->id))->exists()) {
                throw new \RuntimeException('Username membertest sudah digunakan akun lain.');
            }

            $member->forceFill([
                'name' => 'Member Testing', 'username' => 'membertest', 'email' => 'tester@copp.test',
                'password' => 'MemberTest123', 'active' => true, 'is_cohort' => false,
                'email_verified_at' => $member->email_verified_at ?? now(),
                'remember_token' => null, 'deleted_at' => null,
            ])->save();
            $member->assignRole('member');
            DB::table('sessions')->where('user_id', $member->id)->delete();
            DB::table('password_reset_tokens')->where('email', $member->email)->delete();
        });

        $this->command?->info('Member testing siap: membertest / tester@copp.test. Saldo mengikuti transaksi akun.');
    }
}
