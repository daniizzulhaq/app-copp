<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class ReferralService
{
    public function attach(User $user, ?string $code): void
    {
        if (! $code) {
            return;
        }
        $referrer = User::where('referral_code', $code)->where('active', true)->first();
        if (! $referrer || $referrer->id === $user->id) {
            throw ValidationException::withMessages(['referral' => 'Kode referral tidak valid.']);
        }
        DB::transaction(function () use ($user, $referrer) {
            // Serialize network changes so two concurrent attachments cannot create a cycle.
            \App\Models\SystemSetting::firstOrCreate(['key' => 'copp_network_lock'], ['value' => '1']);
            \App\Models\SystemSetting::whereKey('copp_network_lock')->lockForUpdate()->firstOrFail();
            $current = $referrer->id;
            $seen = [$user->id];
            while ($current) {
                if (in_array($current, $seen, true)) {
                    throw ValidationException::withMessages(['referral' => 'Jaringan referral tidak boleh membentuk siklus.']);
                }
                $seen[] = $current;
                $current = Referral::where('referred_id', $current)->value('referrer_id');
            }
            $referral = Referral::firstOrCreate(['referred_id' => $user->id], ['referrer_id' => $referrer->id, 'points' => 0]);
            if ($referral->wasRecentlyCreated) {
                app(AuditService::class)->record('referral.attached', $user, null, ['referrer_id' => $referrer->id]);
                app(MemberBonusService::class)->registration($referrer);
            }
        }, 5);
    }
}
