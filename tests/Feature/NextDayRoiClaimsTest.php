<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\MemberBonus;
use App\Models\Referral;
use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DailyRoiClaimService;
use App\Services\InvestmentService;
use App\Services\LedgerIntegrityService;
use App\Services\TurnoverRoiService;
use App\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\ProductPlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NextDayRoiClaimsTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-21 10:00:00', 'Asia/Jakarta'));
        $this->workspace = SimulationScenario::factory()->create([
            'mode' => 'local', 'plan_version' => 'turnover_v2', 'status' => 'running',
            'start_date' => today(), 'roi_claims_start_date' => today(),
        ]);
        SystemSetting::updateOrCreate(['key' => 'local_workspace'], ['value' => (string) $this->workspace->id]);
        $this->seed(ProductPlanSeeder::class);
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');

        return $member;
    }

    private function buy(?User $member = null): Investment
    {
        $member ??= $this->member();
        app(WalletService::class)->creditDeposit($member, $this->workspace, 100000, 'next-day-test:'.Str::uuid());

        return app(InvestmentService::class)->open($member, $this->workspace,
            RoiPackage::where('active', true)->firstOrFail(), 100000, (string) Str::uuid());
    }

    private function claims(Investment $investment)
    {
        return app(DailyRoiClaimService::class)->forMember($this->workspace, $investment->user);
    }

    private function assertLedgerValid(): void
    {
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_purchase_on_twenty_first_is_claimable_at_midnight_on_twenty_second_without_new_sales(): void
    {
        $investment = $this->buy();
        $this->assertDatabaseHas('daily_product_turnovers', [
            'simulation_scenario_id' => $this->workspace->id, 'period_date' => '2026-11-21', 'gross_amount' => 100000, 'roi_amount' => 25000,
        ]);
        $this->travelTo(Carbon::parse('2026-11-21 23:59:59', 'Asia/Jakarta'));
        $this->assertCount(0, $this->claims($investment));
        $this->travelTo(Carbon::parse('2026-11-22 00:00:00', 'Asia/Jakarta'));
        $claim = $this->claims($investment)->sole();
        $this->assertSame(25000, $claim->remaining());
        $this->assertDatabaseHas('daily_roi_claim_pools', [
            'simulation_scenario_id' => $this->workspace->id, 'period_date' => '2026-11-21', 'claim_date' => '2026-11-22', 'distributable_amount' => 25000,
        ]);
        $this->actingAs($investment->user)->get('/dashboard')->assertOk()->assertSee(route('roi.claim', $claim), false);
        $this->get('/investments')->assertOk()->assertSee(route('roi.claim', $claim), false);
        $this->get('/investments/'.$investment->id)->assertOk()->assertSee(route('roi.claim', $claim), false);
        $this->assertSame(10000, app(WalletService::class)->get($investment->user, $this->workspace)->balance());
        $this->post(route('roi.claim', $claim))->assertSessionHasNoErrors();
        $this->post(route('roi.claim', $claim))->assertSessionHasNoErrors();
        $this->assertSame(35000, app(WalletService::class)->get($investment->user, $this->workspace)->balance());
        $this->assertSame(1, $claim->distributions()->count());
        $this->assertLedgerValid();
    }

    public function test_a_new_purchase_does_not_dilute_todays_claim_and_joins_tomorrows_pool(): void
    {
        $first = $this->buy();
        $this->travelTo(now()->addDay());
        $claim = $this->claims($first)->sole();
        app(DailyRoiClaimService::class)->claim($first->user, $claim);
        $second = $this->buy();
        $this->assertSame(25000, $claim->fresh()->amount);
        $this->assertSame(0, $second->obligations()->count());
        $this->travelTo(now()->addDay());
        $this->assertSame(12500, $this->claims($first)->sole()->remaining());
        $this->assertSame(12500, $this->claims($second)->sole()->remaining());
        $this->assertLedgerValid();
    }

    public function test_one_million_turnover_pays_two_hundred_fifty_to_each_of_one_thousand_active_products(): void
    {
        $first = $this->buy();
        $template = $first->getAttributes();
        unset($template['id']);
        $existing = [];
        for ($n = 1; $n < 1000; $n++) {
            $existing[] = array_replace($template, [
                'number' => 'EQUAL-ROI-'.$first->id.'-'.$n,
                'request_key' => (string) Str::uuid(),
                'starts_at' => today()->subDay()->toDateString(),
            ]);
        }
        foreach (array_chunk($existing, 100) as $chunk) {
            DB::table('investments')->insert($chunk);
        }
        // Fund and post the imported purchases so the fixture also has a complete ledger.
        $importedAmount = 999 * $first->principal;
        app(WalletService::class)->creditDeposit($first->user, $this->workspace, $importedAmount, 'equal-roi-import-deposit');
        app(\App\Services\LedgerService::class)->post($this->workspace, 'equal-roi-import-purchases', 'investment', 'Pembelian fixture produk aktif', [
            ['wallet:'.$first->user_id.':available', 'debit', $importedAmount],
            ['product-sales', 'credit', $importedAmount],
            ['wallet-offset', 'debit', $importedAmount],
            ['reserve', 'credit', $importedAmount],
        ], $first->user_id);
        // Nine more purchases today bring sales to Rp1 million and active products to 1,000.
        DB::table('investments')->where('simulation_scenario_id', $this->workspace->id)
            ->where('id', '!=', $first->id)->orderBy('id')->limit(9)
            ->update(['starts_at' => today()->toDateString()]);
        app(\App\Services\ProductTurnoverService::class)->record($first);
        $this->assertDatabaseHas('daily_product_turnovers', [
            'simulation_scenario_id' => $this->workspace->id, 'period_date' => today()->toDateString(),
            'gross_amount' => 1000000, 'roi_amount' => 250000,
        ]);

        $this->travelTo(now()->addDay());
        $claims = $this->claims($first);
        $this->assertCount(1000, $claims);
        $this->assertSame([250], $claims->pluck('amount')->unique()->values()->all());
        $this->assertSame(250000, $claims->sum('amount'));
        $this->assertCount(1000, $this->claims($first));
        $this->assertLedgerValid();
    }

    public function test_products_receive_equal_profit_even_when_their_old_weights_differ(): void
    {
        $first = $this->buy();
        $second = $this->buy();
        $second->update(['roi_shares' => 10]);
        $this->travelTo(now()->addDay());

        $this->assertSame(25000, $this->claims($first)->sole()->amount);
        $this->assertSame(25000, $this->claims($second)->sole()->amount);
        $this->assertDatabaseHas('daily_roi_claim_pools', [
            'simulation_scenario_id' => $this->workspace->id, 'total_shares' => 2, 'pool_amount' => 50000,
        ]);
        $this->assertDatabaseHas('daily_roi_claim_shares', ['investment_id' => $second->id, 'shares' => 1, 'amount' => 25000]);
        $this->assertLedgerValid();
    }

    public function test_a_day_without_sales_pays_zero_even_when_products_are_active(): void
    {
        $investment = $this->buy();
        $this->travelTo(now()->addDay());
        $claim = $this->claims($investment)->sole();
        app(DailyRoiClaimService::class)->claim($investment->user, $claim);
        $balance = app(WalletService::class)->get($investment->user, $this->workspace)->balance();

        $this->travelTo(now()->addDay());
        $this->assertCount(0, $this->claims($investment));
        $this->assertSame('active', $investment->fresh()->status);
        $this->assertSame($balance, app(WalletService::class)->get($investment->user, $this->workspace)->balance());
        $this->assertLedgerValid();
    }

    public function test_inactive_products_are_excluded_from_the_profit_divisor(): void
    {
        $active = $this->buy();
        $inactive = $this->buy();
        $inactive->update(['status' => 'defaulted']);
        $completed = $this->buy();
        $completed->update(['status' => 'completed']);
        $this->travelTo(now()->addDay());

        $this->assertSame(75000, $this->claims($active)->sole()->amount);
        $this->assertCount(0, $this->claims($inactive));
        $this->assertCount(0, $this->claims($completed));
        $this->assertLedgerValid();
    }

    public function test_missed_days_expire_without_automatically_crediting_the_wallet(): void
    {
        $investment = $this->buy();
        $this->travelTo(now()->addDays(3));
        $this->assertCount(0, $this->claims($investment));
        $expired = $investment->obligations()->sole();
        $this->assertSame('2026-11-22', $expired->due_date->toDateString());
        $this->assertSame(25000, $expired->expired_amount);
        $this->assertSame(0, $expired->paid_amount);
        $this->assertSame(10000, app(WalletService::class)->get($investment->user, $this->workspace)->balance());
        $this->claims($investment);
        $this->assertSame(1, $this->workspace->transactions()->where('kind', 'roi_expired')->count());
        $this->assertLedgerValid();
    }

    public function test_the_final_partial_claim_obeys_the_snapshotted_cap(): void
    {
        RoiPackage::where('active', true)->update(['total_roi_bps' => 3000]);
        $first = $this->buy();
        $this->travelTo(now()->addDay());
        $this->assertSame(25000, $this->claims($first)->sole()->amount);
        $this->buy();
        $this->travelTo(now()->addDay());
        $this->assertSame(5000, $this->claims($first)->sole()->amount);
        $this->assertSame(30000, (int) $first->obligations()->sum('amount'));
        $this->assertSame('completed', $first->fresh()->status);
        $this->buy();
        $this->travelTo(now()->addDay());
        $this->assertCount(0, $this->claims($first));
        $this->assertLedgerValid();
    }

    public function test_three_purchases_still_pay_the_direct_upline_seventy_five_thousand(): void
    {
        $upline = $this->member();
        $buyer = $this->member();
        Referral::create(['referrer_id' => $upline->id, 'referred_id' => $buyer->id, 'points' => 0]);
        for ($purchase = 0; $purchase < 3; $purchase++) {
            $this->buy($buyer);
        }
        $this->assertSame(75000, (int) MemberBonus::where('user_id', $upline->id)->where('kind', 'referral_bonus')->sum('amount'));
        $this->assertSame(3, MemberBonus::where('user_id', $upline->id)->where('kind', 'referral_bonus')->count());
        $this->assertSame(10000, (int) MemberBonus::where('bonus_key', 'cashback:'.$buyer->id.':once')->sum('amount'));
        $this->assertLedgerValid();
    }

    public function test_future_processing_cannot_make_a_purchase_claimable_on_the_purchase_date(): void
    {
        $investment = $this->buy();
        $this->assertSame(0, app(TurnoverRoiService::class)->accrue($this->workspace, today()->addDays(3)));
        $this->assertSame(0, $investment->obligations()->count());
    }

    public function test_transition_recovers_yesterdays_unpaid_pool_like_the_hosting_data(): void
    {
        $this->workspace->update(['plan_version' => 'turnover_v1']);
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'Asia/Jakarta'));
        for ($n = 0; $n < 3; $n++) {
            $this->buy();
        }
        $this->travelTo(now()->addDay());
        for ($n = 0; $n < 3; $n++) {
            $newMemberInvestment = $this->buy();
        }
        $this->travelTo(now()->addDay());
        app(DailyRoiClaimService::class)->prepare($this->workspace);
        $oldPools = DB::table('daily_roi_pools')->where('simulation_scenario_id', $this->workspace->id)->get()->toJson();
        $this->workspace->update(['plan_version' => 'turnover_v2', 'roi_claims_start_date' => today()]);
        $this->assertSame(12500, $this->claims($newMemberInvestment)->sole()->remaining());
        $this->assertDatabaseHas('daily_roi_claim_pools', [
            'simulation_scenario_id' => $this->workspace->id, 'period_date' => '2026-11-21', 'claim_date' => '2026-11-22',
            'pool_amount' => 75000, 'legacy_paid_amount' => 0, 'distributable_amount' => 75000, 'total_shares' => 6,
        ]);
        $this->assertSame($oldPools, DB::table('daily_roi_pools')->where('simulation_scenario_id', $this->workspace->id)->get()->toJson());
        $this->assertSame(0, DB::table('daily_roi_claim_pools')->where('simulation_scenario_id', $this->workspace->id)->whereDate('period_date', '2026-11-20')->count());
        $this->assertLedgerValid();
    }

    public function test_a_legacy_pool_already_paid_is_not_distributed_again(): void
    {
        $this->workspace->update(['plan_version' => 'turnover_v1']);
        $first = $this->buy();
        $this->travelTo(now()->addDay());
        $second = $this->buy();
        $claim = $this->claims($first)->sole();
        app(DailyRoiClaimService::class)->claim($first->user, $claim);
        $this->travelTo(now()->addDay());
        $this->workspace->update(['plan_version' => 'turnover_v2', 'roi_claims_start_date' => today()]);
        $this->assertCount(0, $this->claims($second));
        $this->assertDatabaseHas('daily_roi_claim_pools', [
            'simulation_scenario_id' => $this->workspace->id, 'period_date' => '2026-11-22',
            'pool_amount' => 25000, 'legacy_paid_amount' => 25000, 'distributable_amount' => 0,
        ]);
        $this->assertSame(35000, app(WalletService::class)->get($first->user, $this->workspace)->balance());
        $this->assertLedgerValid();
    }

    public function test_transition_day_with_mixed_legacy_and_new_claims_does_not_pay_the_same_budget_twice(): void
    {
        $this->workspace->update(['plan_version' => 'turnover_v1']);
        $this->travelTo(now()->subDay());
        $first = $this->buy();
        $this->travelTo(now()->addDay());
        $second = $this->buy();
        $this->travelTo(now()->addDay());
        $this->buy();
        $this->workspace->update(['plan_version' => 'turnover_v2', 'roi_claims_start_date' => today()]);
        foreach ([$first, $second] as $investment) {
            $claim = $this->claims($investment)->sole();
            $this->assertSame(25000, $claim->remaining());
            app(DailyRoiClaimService::class)->claim($investment->user, $claim);
        }
        $this->travelTo(now()->addDay());
        $this->assertCount(0, $this->claims($first));
        $this->assertDatabaseHas('daily_roi_claim_pools', [
            'simulation_scenario_id' => $this->workspace->id, 'period_date' => '2026-11-22',
            'pool_amount' => 25000, 'legacy_paid_amount' => 25000, 'distributable_amount' => 0,
        ]);
        $this->assertLedgerValid();
    }
}
