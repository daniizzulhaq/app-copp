<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Notifications\SimulationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvestmentService
{
    public function open(User $user, SimulationScenario $scenario, RoiPackage $package, int $amount, string $key): Investment
    {
        return DB::transaction(function () use ($user, $scenario, $package, $amount, $key) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            if ($old = Investment::where('request_key', $key)->first()) {
                if ($old->user_id !== $user->id || $old->principal !== $amount || $old->roi_package_id !== $package->id || $old->simulation_scenario_id !== $s->id) {
                    throw ValidationException::withMessages(['request_key' => 'Kunci portofolio konflik.']);
                }

                return $old;
            }
            if ($s->status === 'archived') {
                throw ValidationException::withMessages(['scenario' => 'Skenario diarsipkan. Pilih skenario aktif untuk transaksi baru.']);
            }
            $package = RoiPackage::whereKey($package->id)->lockForUpdate()->firstOrFail();
            if ($s->usesTurnoverRoi() && ($package->roi_method !== 'turnover' || ! in_array($package->minimum_amount, [100000, 1000000], true))) {
                throw ValidationException::withMessages(['roi_package_id' => 'Pilih produk Rp100.000 atau Rp1.000.000 pada plan aktif.']);
            }
            if (! $package->active || $amount < $package->minimum_amount) {
                throw ValidationException::withMessages(['amount' => 'Produk Investasi diarsipkan atau nominal di bawah minimum.']);
            }
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($package->fixed_amount && $amount !== $package->minimum_amount) {
                throw ValidationException::withMessages(['amount' => 'Nominal pembelian harus sesuai harga produk.']);
            }
            $purchases = Investment::where('user_id', $user->id);
            if ($s->usesTurnoverRoi()) {
                $purchases->where('simulation_scenario_id', $s->id)->where('roi_method', 'turnover')->where('principal', $package->minimum_amount);
            } else {
                $purchases->where('roi_package_id', $package->id);
            }
            if ($purchases->count() >= $package->purchase_limit) {
                throw ValidationException::withMessages(['roi_package_id' => 'Produk ini maksimal dibeli '.$package->purchase_limit.' kali per akun.']);
            }
            app(WalletService::class)->get($user, $s);
            $start = app(WorkspaceClock::class)->date($s);
            $i = Investment::create(['number' => 'COPP-'.Str::ulid(), 'user_id' => $user->id, 'simulation_scenario_id' => $s->id, 'roi_package_id' => $package->id,
                'package_name' => $package->name, 'principal' => $amount, 'daily_rate_bps' => $package->daily_rate_bps, 'daily_roi_cap' => $package->daily_roi_cap, 'duration_days' => $package->duration_days, 'total_roi_bps' => $package->total_roi_bps,
                'roi_method' => $package->roi_method, 'roi_shares' => $package->roi_shares,
                'payment_frequency_days' => 1, 'starts_at' => $start, 'ends_at' => $package->roi_method === 'turnover' ? '9999-12-31' : $start->copy()->addDays($package->duration_days), 'request_key' => $key]);
            app(LedgerService::class)->post($s, 'investment:'.$i->id, 'investment', 'Aktivasi '.$i->package_name,
                [['wallet:'.$user->id.':available', 'debit', $amount], ['capital', 'credit', $amount],
                    [$s->isLocal() ? 'wallet-offset' : 'cash', 'debit', $amount], ['reserve', 'credit', $amount]], $user->id);
            app(ProductTurnoverService::class)->record($i);
            if ($s->usesTurnoverRoi()) {
                app(GotongRoyongBonusService::class)->activation($i);
            }
            $user->notify(new SimulationNotification('Produk Investasi simulasi aktif', $i->package_name.' telah masuk portofolio.', '/investments/'.$i->id));
            app(AuditService::class)->record('investment.opened', $i, null, $i->toArray(), $s->id);

            return $i;
        }, 5);
    }
}
