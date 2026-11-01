<?php

namespace Database\Seeders;

use App\Models\RoiPackage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CoppSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('copp.plans') as [$price, $daily, $total]) {
            RoiPackage::updateOrCreate(['name' => 'COPP Plan '.number_format($price, 0, ',', '.')], [
                'minimum_amount' => $price, 'daily_rate_bps' => $daily, 'total_roi_bps' => $total,
                'duration_days' => (int) ceil($total / $daily), 'daily_roi_cap' => null,
                'purchase_limit' => 3, 'fixed_amount' => true, 'active' => true,
                'description' => 'COPP Luxury · Sistem jaringan matahari. Maksimal 3 pembelian per produk per akun.',
            ]);
        }
        RoiPackage::where('name', 'not like', 'COPP Plan %')->update(['active' => false]);
        if (! User::where('email', 'admin@copp.test')->exists()) {
            $password = config('nexavest.demo_password') ?: 'Copp!'.Str::random(18).'7aA';
            $admin = User::create(['name' => 'Admin COPP Luxury', 'email' => 'admin@copp.test', 'password' => $password]);
            $admin->forceFill(['email_verified_at' => now()])->save();
            $admin->assignRole('super-admin');
            Storage::disk('local')->put('copp-accounts.txt', "COPP Luxury\nAdmin: admin@copp.test\nKata sandi: {$password}\n");
            $this->command?->info('Akun admin: storage/app/private/copp-accounts.txt');
        }
        if (! User::withTrashed()->where('email', 'member@copp.test')->exists()) {
            $password = config('nexavest.demo_password') ?: 'Copp!'.Str::random(18).'7aA';
            $member = User::create(['name' => 'Member Testing COPP', 'email' => 'member@copp.test', 'password' => $password]);
            $member->forceFill(['email_verified_at' => now()])->save();
            $member->assignRole('member');
            Storage::disk('local')->put('copp-member-account.txt', "COPP Luxury - Member Testing\nEmail: member@copp.test\nKata sandi: {$password}\n");
            $this->command?->info('Akun member testing: storage/app/private/copp-member-account.txt');
        }
    }
}
