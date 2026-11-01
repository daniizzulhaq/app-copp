<?php

namespace Database\Seeders;

use App\Models\RoiPackage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

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
        $password = 'coppluxury123';
        $admin = User::withTrashed()->firstOrNew(['email' => 'admin@copp.test'], ['name' => 'Admin COPP Luxury']);
        if (! $admin->trashed()) {
            $admin->forceFill(['password' => $password, 'remember_token' => null, 'email_verified_at' => $admin->email_verified_at ?? now()])->save();
            $admin->assignRole('super-admin');
            Storage::disk('local')->put('copp-accounts.txt', "COPP Luxury\nAdmin: admin@copp.test\nKata sandi: {$password}\n");
            $this->command?->info('Akun admin: storage/app/private/copp-accounts.txt');
        }
        $member = User::withTrashed()->firstOrNew(['email' => 'member@copp.test'], ['name' => 'Member Testing COPP']);
        if (! $member->trashed()) {
            $member->forceFill(['password' => $password, 'remember_token' => null, 'email_verified_at' => $member->email_verified_at ?? now()])->save();
            $member->assignRole('member');
            Storage::disk('local')->put('copp-member-account.txt', "COPP Luxury - Member Testing\nEmail: member@copp.test\nKata sandi: {$password}\n");
            $this->command?->info('Akun member testing: storage/app/private/copp-member-account.txt');
        }
    }
}
