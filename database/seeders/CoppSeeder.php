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
        $password = 'admin123';
        $admin = User::withTrashed()->firstOrNew(['email' => 'admin@copp.test'], ['name' => 'Didisaidi']);
        if (! $admin->trashed()) {
            $admin->forceFill(['name' => 'Didisaidi', 'password' => $password, 'remember_token' => null, 'email_verified_at' => $admin->email_verified_at ?? now()])->save();
            $admin->assignRole('super-admin');
            Storage::disk('local')->put('copp-accounts.txt', "COPP Luxury\nAdmin: admin@copp.test\nKata sandi: {$password}\n");
            $this->command?->info('Akun admin: storage/app/private/copp-accounts.txt');
        }
    }
}
