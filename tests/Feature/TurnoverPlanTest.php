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
use App\Services\GotongRoyongBonusService;
use App\Services\InvestmentService;
use App\Services\LedgerIntegrityService;
use App\Services\ManualDepositService;
use App\Services\MemberPlanResetService;
use App\Services\ReferralService;
use App\Services\ROIObligationService;
use App\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\ProductPlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TurnoverPlanTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Retain coverage of previously sold weighted products; the live catalog is now one product.
        config(['copp.plans' => [[100000, 0, 21000, 1], [1000000, 0, 21000, 10]], 'copp.roi_pool_bps' => 6000, 'copp.returns_principal' => true]);
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'Asia/Jakarta'));
        $this->workspace = SimulationScenario::factory()->create([
            'mode' => 'local', 'plan_version' => 'turnover_v1', 'status' => 'running', 'start_date' => today(),
        ]);
        SystemSetting::updateOrCreate(['key' => 'local_workspace'], ['value' => (string) $this->workspace->id]);
        $this->seed(ProductPlanSeeder::class);
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $user->assignRole('member');

        return $user;
    }

    private function buy(User $user, int $price = 100000, ?string $key = null): Investment
    {
        $package = RoiPackage::where('active', true)->where('minimum_amount', $price)->firstOrFail();

        return app(InvestmentService::class)->open($user, $this->workspace, $package, $price, $key ?? (string) Str::uuid());
    }

    private function fund(User $user, int $amount): void
    {
        app(WalletService::class)->creditDeposit($user, $this->workspace, $amount, 'test-funding:'.Str::uuid());
    }

    public function test_catalog_has_only_two_prices_and_snapshot_shares_are_one_and_ten(): void
    {
        RoiPackage::factory()->create(['minimum_amount' => 200000]);
        $this->seed(ProductPlanSeeder::class);
        $this->seed(ProductPlanSeeder::class);
        $this->assertSame([100000, 1000000], RoiPackage::where('active', true)->orderBy('minimum_amount')->pluck('minimum_amount')->all());
        $member = $this->member();
        $this->fund($member, 1100000);
        $small = $this->buy($member);
        $large = $this->buy($member, 1000000);
        $this->assertSame(1, $small->roi_shares);
        $this->assertSame(10, $large->roi_shares);
        $this->assertSame(210000, $small->promised());
        $this->assertSame(2100000, $large->promised());
        $small->package->update(['roi_shares' => 10, 'total_roi_bps' => 100]);
        $this->assertSame(1, $small->refresh()->roi_shares);
        $this->assertSame(210000, $small->promised());
        $this->actingAs($member)->get('/packages')->assertOk()->assertSee('1 bagian')->assertDontSee('Plan 200.000');
        $this->get('/investments')->assertOk()->assertSee('bagian ROI')->assertDontSee('9999');
        $this->get('/dashboard')->assertOk()->assertSee('bagian ROI')->assertDontSee('9999');
        $this->get('/investments/'.$small->id)->assertOk()->assertSee('pool 60%');
    }

    public function test_pool_is_sixty_percent_and_weights_are_proportional_without_duplicate_accrual(): void
    {
        $smallOwner = $this->member();
        $largeOwner = $this->member();
        $this->fund($smallOwner, 1000000);
        $this->fund($largeOwner, 1000000);
        for ($n = 0; $n < 10; $n++) {
            $small[] = $this->buy($smallOwner);
        }
        $large = $this->buy($largeOwner, 1000000);
        $this->travelTo(now()->addDay());
        $buyer = $this->member();
        $this->fund($buyer, 1000000);
        $new = $this->buy($buyer, 1000000);
        app(ROIObligationService::class)->accrue($this->workspace, today());
        app(ROIObligationService::class)->accrue($this->workspace, today());
        foreach ($small as $i) {
            $this->assertSame(30000, (int) $i->obligations()->sum('amount'));
        }
        $this->assertSame(300000, (int) $large->obligations()->sum('amount'));
        $this->assertSame(0, $new->obligations()->count());
        $this->assertDatabaseHas('daily_roi_pools', ['simulation_scenario_id' => $this->workspace->id,
            'period_date' => today()->toDateString(), 'total_shares' => 20, 'pool_amount' => 600000]);
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_no_turnover_means_zero_roi_even_after_a_long_time(): void
    {
        $member = $this->member();
        $this->fund($member, 100000);
        $i = $this->buy($member);
        $this->travelTo(now()->addDays(100));
        app(DailyRoiClaimService::class)->prepare($this->workspace);
        $this->assertSame(0, $i->obligations()->count());
        $this->assertSame(0, $i->refresh()->principal_returned_amount);
        $this->assertSame('active', $i->status);
        $this->assertSame(10000, app(WalletService::class)->get($member, $this->workspace)->balance());
    }

    public function test_ten_purchase_limit_is_independent_for_each_price_and_survives_catalog_replacement(): void
    {
        $member = $this->member();
        $this->fund($member, 12000000);
        foreach ([100000, 1000000] as $price) {
            for ($n = 0; $n < 10; $n++) {
                $last = $this->buy($member, $price);
            }
            $last->update(['status' => 'completed']);
            try {
                $this->buy($member, $price);
                $this->fail('Eleventh purchase should be rejected for each price.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('roi_package_id', $e->errors());
            }
        }
        $old = RoiPackage::where('minimum_amount', 100000)->where('active', true)->firstOrFail();
        $old->update(['active' => false]);
        RoiPackage::create(array_replace($old->getAttributes(), ['id' => null, 'name' => 'Replacement', 'active' => true]));
        try {
            $this->buy($member);
            $this->fail('Replacing the product ID must not reset the account limit.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('roi_package_id', $e->errors());
        }
        $this->assertSame(20, $member->investments()->where('simulation_scenario_id', $this->workspace->id)->count());
    }

    public function test_unclaimed_roi_expires_and_a_day_without_turnover_does_not_add_roi(): void
    {
        $owner = $this->member();
        $this->fund($owner, 1000000);
        $i = $this->buy($owner, 1000000);
        $this->travelTo(now()->addDay());
        $buyer = $this->member();
        $this->fund($buyer, 100000);
        $this->buy($buyer);
        $claim = $i->obligations()->whereDate('due_date', today())->firstOrFail();
        $this->travelTo(now()->addDay());
        try {
            app(DailyRoiClaimService::class)->claim($owner, $claim);
            $this->fail('Past claims should expire.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('claim', $e->errors());
        }
        $this->assertSame(60000, $claim->refresh()->expired_amount);
        $this->assertSame(0, $i->obligations()->whereDate('due_date', today())->count());
        $this->assertSame(10000, app(WalletService::class)->get($owner, $this->workspace)->balance());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_claims_can_collect_same_day_additions_once_without_diluting_previous_claims(): void
    {
        $owner = $this->member();
        $this->fund($owner, 1000000);
        $i = $this->buy($owner, 1000000);
        $this->travelTo(now()->addDay());
        $buyer = $this->member();
        $this->fund($buyer, 200000);
        $this->buy($buyer);
        $service = app(DailyRoiClaimService::class);
        $claim = $i->obligations()->whereDate('due_date', today())->firstOrFail();
        $service->claim($owner, $claim);
        $service->claim($owner, $claim);
        $this->assertSame(70000, app(WalletService::class)->get($owner, $this->workspace)->balance());
        $this->buy($buyer);
        $this->assertSame(120000, $claim->refresh()->amount);
        $this->assertSame(60000, $claim->remaining());
        $service->claim($owner, $claim);
        $service->claim($owner, $claim);
        $this->assertSame(130000, app(WalletService::class)->get($owner, $this->workspace)->balance());
        $this->assertSame(2, $claim->distributions()->count());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_cap_is_respected_and_capped_products_leave_the_following_days_pool(): void
    {
        $owner = $this->member();
        $this->fund($owner, 100000);
        $small = $this->buy($owner);
        $this->travelTo(now()->addDay());
        $buyer = $this->member();
        $this->fund($buyer, 1100000);
        $large = $this->buy($buyer, 1000000);
        $service = app(DailyRoiClaimService::class);
        $claim = $small->obligations()->firstOrFail();
        $this->assertSame(210000, $claim->amount);
        $service->claim($owner, $claim);
        $service->prepare($this->workspace);
        $this->assertSame('completed', $small->refresh()->status);
        $this->assertSame(100000, $small->principal_returned_amount);
        $this->assertSame(320000, app(WalletService::class)->get($owner, $this->workspace)->balance());
        $this->travelTo(now()->addDay());
        $this->buy($buyer);
        $this->assertDatabaseHas('daily_roi_pools', ['simulation_scenario_id' => $this->workspace->id,
            'period_date' => today()->toDateString(), 'total_shares' => 10]);
        $this->assertSame(60000, (int) $large->obligations()->whereDate('due_date', today())->sum('amount'));
        $this->assertSame(210000, (int) $small->obligations()->sum('amount'));
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_signup_and_each_invitation_pay_once_with_no_old_mission_rewards(): void
    {
        $parent = $this->member();
        $this->travelTo(now()->addMinute());
        $data = ['name' => 'New Account', 'username' => 'newaccount', 'email' => 'new@copp.test',
            'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123', 'referral' => $parent->referral_code];
        $this->post('/register', $data)->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $child = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame(5000, app(WalletService::class)->get($parent, $this->workspace)->balance());
        $this->assertSame(0, app(WalletService::class)->get($child, $this->workspace)->balance());
        $this->get('/dashboard')->assertOk()->assertSee('Klaim bonus daftar Rp5.000');
        $this->post('/bonuses/registration/claim')->assertSessionHasNoErrors();
        $this->post('/bonuses/registration/claim')->assertSessionHasNoErrors();
        $this->assertSame(5000, app(WalletService::class)->get($child, $this->workspace)->balance());
        app(ReferralService::class)->attach($child, $parent->referral_code);
        $other = $this->member();
        app(ReferralService::class)->attach($other, $parent->referral_code);
        $this->assertSame(10000, app(WalletService::class)->get($parent, $this->workspace)->balance());
        $this->assertSame(3, MemberBonus::where('simulation_scenario_id', $this->workspace->id)->count());
        $this->get('/referrals')->assertOk()->assertSee('Level 5')->assertDontSee('Level 6');
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_deposit_alone_has_no_bonus_and_product_commissions_stop_at_five_total_levels(): void
    {
        $chain = [$this->member()];
        for ($n = 1; $n <= 7; $n++) {
            $chain[] = $this->member();
            Referral::create(['referrer_id' => $chain[$n - 1]->id, 'referred_id' => $chain[$n]->id, 'points' => 0]);
        }
        $buyer = $chain[7];
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $service = app(ManualDepositService::class);
        $invoice = $service->create($buyer, $this->workspace, 200000, (string) Str::uuid());
        $invoice->update(['status' => 'awaiting_review', 'reference' => 'Testing']);
        $service->review($invoice, $admin, true, null);
        $service->review($invoice, $admin, true, null);
        $this->assertSame(0, MemberBonus::where('simulation_scenario_id', $this->workspace->id)->count());
        $key = (string) Str::uuid();
        $i = $this->buy($buyer, 100000, $key);
        $this->buy($buyer, 100000, $key);
        $this->assertSame(25000, (int) MemberBonus::where('user_id', $chain[6]->id)->sum('amount'));
        foreach (range(1, 4) as $level) {
            $this->assertSame(5000, (int) MemberBonus::where('user_id', $chain[6 - $level]->id)->sum('amount'));
        }
        $this->assertSame(0, MemberBonus::where('user_id', $chain[0]->id)->count());
        $this->assertSame(110000, app(WalletService::class)->get($buyer, $this->workspace)->balance());
        $this->buy($buyer);
        $this->assertSame(1, MemberBonus::where('bonus_key', 'cashback:'.$buyer->id.':once')->count());
        $this->assertSame(0, MemberBonus::where('user_id', $chain[0]->id)->count());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_reset_zeros_current_balances_and_products_preserving_accounts_network_and_old_ledger(): void
    {
        $old = SimulationScenario::factory()->create(['mode' => 'local', 'plan_version' => 'legacy', 'status' => 'running']);
        SystemSetting::whereKey('local_workspace')->update(['value' => (string) $old->id]);
        $member = $this->member();
        $child = $this->member();
        Referral::create(['referrer_id' => $member->id, 'referred_id' => $child->id, 'points' => 0]);
        app(WalletService::class)->creditDeposit($member, $old, 500000, 'old-wallet:'.$member->id);
        $oldPackage = RoiPackage::factory()->create();
        app(InvestmentService::class)->open($member, $old, $oldPackage, 100000, (string) Str::uuid());
        $users = User::withTrashed()->count();
        $password = $member->password;
        $this->travelTo(now()->addMinute());
        $active = app(MemberPlanResetService::class)->reset();
        $this->assertSame($active->id, app(MemberPlanResetService::class)->reset()->id);
        $this->assertSame('archived', $old->refresh()->status);
        $this->assertSame($users, User::withTrashed()->count());
        $this->assertSame($password, $member->refresh()->password);
        $this->assertDatabaseHas('referrals', ['referrer_id' => $member->id, 'referred_id' => $child->id]);
        $this->assertSame(0, $active->investments()->count());
        $this->assertSame(0, app(WalletService::class)->get($member, $active)->balance());
        $this->assertSame(400000, app(WalletService::class)->get($member, $old)->balance());
        $this->assertFalse(app(GotongRoyongBonusService::class)->canClaimRegistration($member, $active));
        $this->assertSame(30000, SystemSetting::minimumWithdrawalAmount());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($old));
        $this->assertSame([], app(LedgerIntegrityService::class)->check($active));
    }
}
