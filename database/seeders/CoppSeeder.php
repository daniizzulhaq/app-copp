<?php

namespace Database\Seeders;

use App\Models\RoiPackage;
use Illuminate\Database\Seeder;

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
        $this->call(AccountSeeder::class);
    }
}
