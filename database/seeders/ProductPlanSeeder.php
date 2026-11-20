<?php

namespace Database\Seeders;

use App\Models\RoiPackage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductPlanSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $ids = [];
            foreach (config('copp.plans') as [$price, $daily, $total, $shares]) {
                $product = RoiPackage::updateOrCreate(['name' => 'COPP Plan '.number_format($price, 0, ',', '.')], [
                    'minimum_amount' => $price, 'daily_rate_bps' => 0, 'total_roi_bps' => $total,
                    'duration_days' => 0, 'daily_roi_cap' => null, 'roi_method' => 'turnover', 'roi_shares' => $shares,
                    'purchase_limit' => config('copp.purchase_limit'), 'fixed_amount' => true, 'active' => true,
                    'description' => $shares.' bagian ROI dari pool 60% omzet produk. Maksimal 10 pembelian per produk per akun.',
                ]);
                $ids[] = $product->id;
            }
            RoiPackage::whereNotIn('id', $ids)->update(['active' => false]);
        }, 5);
    }
}
