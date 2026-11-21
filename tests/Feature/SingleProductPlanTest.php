<?php

namespace Tests\Feature;

use App\Models\MemberBonus;
use App\Models\MockPaymentInvoice;
use App\Models\Referral;
use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\DailyRoiClaimService;
use App\Services\DashboardService;
use App\Services\GotongRoyongBonusService;
use App\Services\InvestmentService;
use App\Services\LedgerIntegrityService;
use App\Services\PrincipalReturnService;
use App\Services\ProductTurnoverService;
use App\Services\WalletService;
use App\Services\WithdrawalActivityService;
use Database\Seeders\ProductPlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SingleProductPlanTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
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

    public function test_catalog_has_one_package_and_cannot_activate_the_old_million_package(): void
    {
        $old = RoiPackage::factory()->create(['minimum_amount' => 1000000, 'roi_method' => 'turnover', 'roi_shares' => 10]);
        $this->seed(ProductPlanSeeder::class);
        $this->seed(ProductPlanSeeder::class);
        $this->assertSame([100000], RoiPackage::where('active', true)->pluck('minimum_amount')->all());
        $this->assertFalse($old->refresh()->active);
        $member = $this->member();
        $this->actingAs($member)->get('/packages')->assertOk()->assertSee('Satu paket Rp100.000')
            ->assertDontSee('Rp1.000.000');
        $this->expectException(ValidationException::class);
        app(InvestmentService::class)->open($member, $this->workspace, $old, 1000000, (string) Str::uuid());
    }

    public function test_first_purchase_cashback_is_once_and_network_stops_after_five_total_uplines(): void
    {
        $chain = [$this->member()];
        for ($n = 1; $n <= 6; $n++) {
            $chain[] = $this->member();
            Referral::create(['referrer_id' => $chain[$n - 1]->id, 'referred_id' => $chain[$n]->id, 'points' => 0]);
        }
        $buyer = $chain[6];
        app(WalletService::class)->creditDeposit($buyer, $this->workspace, 200000, 'first-purchase:'.$buyer->id);
        $package = RoiPackage::where('active', true)->firstOrFail();
        $key = (string) Str::uuid();
        $service = app(InvestmentService::class);
        $first = $service->open($buyer, $this->workspace, $package, 100000, $key);
        $service->open($buyer, $this->workspace, $package, 100000, $key);
        $this->assertSame(25000, (int) MemberBonus::where('user_id', $chain[5]->id)->sum('amount'));
        foreach ([1, 2, 3, 4] as $index) {
            $this->assertSame(5000, (int) MemberBonus::where('user_id', $chain[$index]->id)->sum('amount'));
        }
        $this->assertSame(0, MemberBonus::where('user_id', $chain[0]->id)->count());
        $this->assertSame(0, MockPaymentInvoice::where('user_id', $buyer->id)->count());
        $this->assertSame(10000, (int) MemberBonus::where('user_id', $buyer->id)->sum('amount'));
        $service->open($buyer, $this->workspace, $package, 100000, (string) Str::uuid());
        app(GotongRoyongBonusService::class)->activation($first);
        $this->assertSame(1, MemberBonus::where('bonus_key', 'cashback:'.$buyer->id.':once')->count());
        $this->assertSame(10000, app(WalletService::class)->get($buyer, $this->workspace)->balance());
        $this->assertSame(0, MemberBonus::where('user_id', $chain[0]->id)->count());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
        $this->actingAs($buyer)->get('/referrals')->assertOk()->assertSee('25%')->assertSee('Level 5')->assertDontSee('Level 6');
    }

    public function test_three_purchases_pay_every_network_level_three_times_even_without_a_deposit_invoice(): void
    {
        $chain = [$this->member()];
        for ($n = 1; $n <= 6; $n++) {
            $chain[] = $this->member();
            Referral::create(['referrer_id' => $chain[$n - 1]->id, 'referred_id' => $chain[$n]->id, 'points' => 0]);
        }
        $buyer = $chain[6];
        $wallets = app(WalletService::class);
        $wallets->creditDeposit($buyer, $this->workspace, 300000, 'three-purchases:'.$buyer->id);
        $package = RoiPackage::where('active', true)->firstOrFail();
        $service = app(InvestmentService::class);
        $investments = [];
        for ($purchase = 1; $purchase <= 3; $purchase++) {
            $key = (string) Str::uuid();
            $investment = $service->open($buyer, $this->workspace, $package, 100000, $key);
            $investments[] = $investment;
            // Retrying the same purchase or bonus processing must not pay it twice.
            $this->assertSame($investment->id, $service->open($buyer, $this->workspace, $package, 100000, $key)->id);
            app(GotongRoyongBonusService::class)->activation($investment);

            $report = app(ProductTurnoverService::class)->report($this->workspace, today()->toDateString(), today()->toDateString());
            $this->assertSame($purchase * 100000, $report['gross']);
            $this->assertSame($purchase * 25000, $report['roi']);
            $this->assertSame($purchase, $report['today']->purchase_count);
            $this->assertSame($purchase * 100000, $report['today']->gross_amount);
            $this->assertSame($purchase * 25000, $report['today']->roi_amount);

            for ($level = 1; $level <= 5; $level++) {
                $upline = $chain[6 - $level];
                $amount = $level === 1 ? 25000 : 5000;
                $bonuses = MemberBonus::where('user_id', $upline->id)->where('kind', 'referral_bonus');
                $this->assertSame($purchase, (clone $bonuses)->count());
                $this->assertSame($purchase * $amount, (int) $bonuses->sum('amount'));
                $this->assertSame($purchase * $amount, $wallets->get($upline, $this->workspace)->balance());
            }
        }

        // An unaffordable fourth purchase cannot create any additional commission.
        try {
            $service->open($buyer, $this->workspace, $package, 100000, (string) Str::uuid());
            $this->fail('A purchase without enough balance must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        $this->assertSame(3, $buyer->investments()->count());
        $report = app(ProductTurnoverService::class)->report($this->workspace, today()->toDateString(), today()->toDateString());
        $this->assertSame(300000, $report['gross']);
        $this->assertSame(75000, $report['roi']);
        $this->assertSame(3, $report['today']->purchase_count);
        $this->assertSame(15, MemberBonus::where('simulation_scenario_id', $this->workspace->id)->where('kind', 'referral_bonus')->count());
        $this->assertSame(0, MemberBonus::where('user_id', $chain[0]->id)->count());
        $this->assertSame(0, MockPaymentInvoice::where('user_id', $buyer->id)->count());
        $this->assertSame(10000, $wallets->get($buyer, $this->workspace)->balance());
        $this->assertSame(1, MemberBonus::where('bonus_key', 'cashback:'.$buyer->id.':once')->count());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
        $page = $this->actingAs($chain[5])->get('/referrals')->assertOk()->assertSee('Rp75.000');
        foreach ($investments as $investment) {
            $page->assertSee($investment->number);
        }
    }

    public function test_one_hundred_thousand_turnover_allocates_twenty_five_thousand_to_active_products(): void
    {
        $package = RoiPackage::where('active', true)->firstOrFail();
        $owners = [$this->member(), $this->member()];
        $investments = [];
        foreach ($owners as $owner) {
            app(WalletService::class)->creditDeposit($owner, $this->workspace, 100000, 'roi-owner:'.$owner->id);
            $investments[] = app(InvestmentService::class)->open($owner, $this->workspace, $package, 100000, (string) Str::uuid());
        }
        $this->travelTo(now()->addDay());
        $buyer = $this->member();
        app(WalletService::class)->creditDeposit($buyer, $this->workspace, 100000, 'roi-buyer:'.$buyer->id);
        $new = app(InvestmentService::class)->open($buyer, $this->workspace, $package, 100000, (string) Str::uuid());
        $report = app(ProductTurnoverService::class)->report($this->workspace, today()->toDateString(), today()->toDateString());
        $this->assertSame(100000, $report['gross']);
        $this->assertSame(25000, $report['roi']);
        $this->assertSame(2500, $report['today']->roi_rate_bps);
        foreach ($investments as $i) {
            $this->assertSame(12500, (int) $i->obligations()->sum('amount'));
        }
        $this->assertSame(0, $new->obligations()->count());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_product_finishes_at_two_hundred_ten_thousand_without_an_extra_principal_payment(): void
    {
        $owner = $this->member();
        $package = RoiPackage::where('active', true)->firstOrFail();
        app(WalletService::class)->creditDeposit($owner, $this->workspace, 100000, 'cap-owner:'.$owner->id);
        $investment = app(InvestmentService::class)->open($owner, $this->workspace, $package, 100000, (string) Str::uuid());
        $this->assertFalse($investment->returns_principal);
        $this->assertSame(210000, $investment->maximumPayout());
        // Editing the catalog cannot change the purchase's repayment policy.
        $package->update(['returns_principal' => true]);
        $this->assertFalse($investment->refresh()->returns_principal);
        $package->update(['returns_principal' => false]);
        $this->travelTo(now()->addDay());
        $buyer = $this->member();
        app(WalletService::class)->creditDeposit($buyer, $this->workspace, 1000000, 'cap-buyer:'.$buyer->id);
        for ($n = 0; $n < 9; $n++) {
            app(InvestmentService::class)->open($buyer, $this->workspace, $package, 100000, (string) Str::uuid());
        }
        $this->assertSame('completed', $investment->refresh()->status);
        $claim = $investment->obligations()->whereDate('due_date', today())->firstOrFail();
        $this->assertSame(210000, $claim->amount);
        $claims = app(DailyRoiClaimService::class);
        $claims->claim($owner, $claim);
        $claims->claim($owner, $claim);
        $claims->prepare($this->workspace);
        $this->assertSame(0, app(PrincipalReturnService::class)->pay($this->workspace, today()->toDateString()));
        $this->assertSame(0, app(PrincipalReturnService::class)->outstanding($this->workspace, today()->toDateString()));
        $this->assertSame(0, $investment->refresh()->principal_returned_amount);
        // Only the claimed product cap plus the separate, one-time cashback.
        $this->assertSame(220000, app(WalletService::class)->get($owner, $this->workspace)->balance());
        $this->assertSame(0, $this->workspace->transactions()->where('kind', 'principal_returned')->count());
        $dashboard = app(DashboardService::class)->data($this->workspace, today()->toDateString(), today()->toDateString(), $owner->id);
        $this->assertSame(0, $dashboard['metrics']['active_principal']);
        $this->assertSame(1, $dashboard['metrics']['completed_investments']);
        $this->actingAs($owner)->get('/investments/'.$investment->id)->assertOk()
            ->assertSee('tanpa pengembalian modal tambahan')->assertDontSee('Rp310.000');
        $this->travelTo(now()->addDay());
        app(InvestmentService::class)->open($buyer, $this->workspace, $package, 100000, (string) Str::uuid());
        $this->assertSame(0, $investment->obligations()->whereDate('due_date', today())->count());
        $this->assertDatabaseHas('daily_roi_pools', ['simulation_scenario_id' => $this->workspace->id,
            'period_date' => today()->toDateString(), 'total_shares' => 9]);
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_home_is_the_registration_form_with_referral_and_no_mission_marketing(): void
    {
        $parent = $this->member();
        $this->get('/?ref='.$parent->referral_code)->assertOk()->assertSee('Buat akun')
            ->assertSee('action="/register"', false)->assertSee($parent->referral_code)
            ->assertDontSee('Misi member')->assertDontSee('Master plan COPP')->assertDontSee('landing-section', false);
        $this->get('/register')->assertOk()->assertSee('registration-card', false);
        $this->actingAs($parent)->get('/')->assertRedirect('/dashboard');
    }

    public function test_wd_chart_uses_completed_withdrawals_and_unique_members_without_private_data(): void
    {
        $first = $this->member();
        $second = $this->member();
        $create = function (User $user, array $attributes = []) {
            return WithdrawalRequest::create(array_replace([
                'user_id' => $user->id, 'simulation_scenario_id' => $this->workspace->id,
                'amount' => 30000, 'admin_fee' => 3000, 'method' => 'bank_transfer', 'status' => 'approved',
                'reviewed_at' => now(), 'request_key' => (string) Str::uuid(),
            ], $attributes));
        };
        $create($first);
        $create($first);
        $create($second);
        $create($second, ['status' => 'pending', 'reviewed_at' => null]);
        $create($second, ['status' => 'rejected']);
        $other = SimulationScenario::factory()->create(['mode' => 'local']);
        $create($second, ['simulation_scenario_id' => $other->id]);
        $report = app(WithdrawalActivityService::class)->report($this->workspace, today()->subDay()->toDateString(), today()->toDateString());
        $this->assertSame([0, 2], $report['chart']['members']);
        $this->assertSame([0, 81000], $report['chart']['amounts']);
        $this->assertSame(2, $report['member_count']);
        $this->assertSame(81000, $report['net_amount']);
        $this->assertStringNotContainsString($first->email, json_encode($report));
        $this->actingAs($first)->get('/dashboard')->assertOk()->assertSee('Member yang sudah WD')
            ->assertSee('withdrawal-activity-data', false)->assertSee('Rp81.000');
    }
}
