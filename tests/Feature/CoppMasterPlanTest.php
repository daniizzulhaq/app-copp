<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\MemberBonus;
use App\Models\MockPaymentInvoice;
use App\Models\Referral;
use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\LedgerIntegrityService;
use App\Services\ManualDepositService;
use App\Services\MemberBonusService;
use App\Services\ReferralService;
use App\Services\ROIObligationService;
use App\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\CoppSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CoppMasterPlanTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'Asia/Jakarta'));
        $this->workspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running', 'start_date' => today()]);
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
    }

    private function fund(int $amount = 100000000): void
    {
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, $amount, 'fund:'.$this->member->id);
    }

    private function invoice(User $user, int $amount = 100000): MockPaymentInvoice
    {
        return MockPaymentInvoice::create([
            'number' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(), 'user_id' => $user->id,
            'simulation_scenario_id' => $this->workspace->id, 'amount' => $amount, 'workflow' => 'manual_local',
            'method' => 'manual_local', 'status' => 'paid_local', 'expires_at' => now()->addDay(), 'paid_at' => now(),
        ]);
    }

    public function test_reseeding_updates_admin_without_changing_wallets_or_other_members(): void
    {
        Storage::fake('local');
        $this->seed(CoppSeeder::class);
        $accounts = User::where('email', 'admin@copp.test')->get();
        $this->assertCount(1, $accounts);
        $otherPassword = $this->member->password;
        $userCount = User::count();

        foreach ($accounts as $account) {
            $account->update(['password' => 'PreviousPassword123']);
            app(WalletService::class)->creditDeposit($account, $this->workspace, 100000, 'test-seeder:'.$account->id);
        }
        $transactionCount = $this->workspace->transactions()->count();

        $this->seed(CoppSeeder::class);

        foreach ($accounts as $account) {
            $account->refresh();
            $this->assertSame('Didisaidi', $account->name);
            $this->assertTrue(Hash::check('admin123', $account->password));
            $this->assertFalse(Hash::check('PreviousPassword123', $account->password));
            $wallet = app(WalletService::class)->get($account, $this->workspace);
            $this->assertSame(100000, $wallet->balance());
        }
        $this->assertSame($userCount, User::count());
        $this->assertSame($otherPassword, $this->member->refresh()->password);
        $this->assertSame($transactionCount, $this->workspace->transactions()->count());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_all_six_plans_have_exact_total_roi_and_a_capped_final_day(): void
    {
        $this->seed(CoppSeeder::class);
        $this->fund();
        foreach (config('copp.plans') as [$price, $daily, $total]) {
            $p = RoiPackage::where('minimum_amount', $price)->where('active', true)->firstOrFail();
            $this->assertSame($daily, $p->daily_rate_bps);
            $this->assertSame($total, $p->total_roi_bps);
            $i = app(InvestmentService::class)->open($this->member, $this->workspace, $p, $price, (string) Str::uuid());
            // Product edits cannot alter an existing investment's cap.
            $p->update(['total_roi_bps' => 100]);
            $through = $i->ends_at->copy()->addDays(10);
            app(ROIObligationService::class)->accrue($this->workspace, $through);
            app(ROIObligationService::class)->accrue($this->workspace, $through);
            $this->assertSame(intdiv($price * $total, 10000), $i->promised());
            $this->assertSame($i->promised(), (int) $i->obligations()->sum('amount'));
            $this->assertLessThanOrEqual(intdiv($price * $daily, 10000), (int) $i->obligations()->max('amount'));
        }
        $expected = Investment::where('user_id', $this->member->id)->get()->sum(fn ($investment) => $investment->promised());
        $dashboard = app(\App\Services\DashboardService::class)->data($this->workspace, '2026-11-01', '2026-11-01', $this->member->id);
        $this->assertSame($expected, $dashboard['metrics']['promised']);
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_purchase_limit_counts_completed_products_and_other_workspaces_but_allows_retries(): void
    {
        $this->fund();
        $p = RoiPackage::factory()->create(['purchase_limit' => 3, 'fixed_amount' => true]);
        $service = app(InvestmentService::class);
        for ($n = 0; $n < 3; $n++) {
            $key = (string) Str::uuid();
            $i = $service->open($this->member, $this->workspace, $p, 100000, $key);
            $this->assertSame($i->id, $service->open($this->member, $this->workspace, $p, 100000, $key)->id);
        }
        $i->update(['status' => 'completed']);
        $other = SimulationScenario::factory()->create(['mode' => 'local']);
        $before = Investment::where('user_id', $this->member->id)->count();
        try {
            $service->open($this->member, $other, $p, 100000, (string) Str::uuid());
            $this->fail('Fourth purchase must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('roi_package_id', $e->errors());
        }
        $this->assertSame($before, Investment::where('user_id', $this->member->id)->count());
    }

    public function test_fixed_price_rejects_a_different_principal(): void
    {
        $this->fund();
        $p = RoiPackage::factory()->create(['fixed_amount' => true]);
        $this->expectException(ValidationException::class);
        app(InvestmentService::class)->open($this->member, $this->workspace, $p, 200000, (string) Str::uuid());
    }

    public function test_commissions_follow_fifteen_levels_once_and_stop_before_level_sixteen(): void
    {
        $chain = [$this->member];
        for ($n = 1; $n <= 16; $n++) {
            $child = User::factory()->create();
            Referral::create(['referrer_id' => $chain[$n - 1]->id, 'referred_id' => $child->id, 'points' => 0]);
            $chain[] = $child;
        }
        $invoice = $this->invoice($chain[16]);
        $service = app(MemberBonusService::class);
        $service->topup($invoice);
        $service->topup($invoice);
        foreach (config('copp.referral_rates_bps') as $level => $rate) {
            $this->assertSame($rate * 10, (int) MemberBonus::where('user_id', $chain[16 - $level]->id)->where('kind', 'referral_bonus')->sum('amount'));
        }
        $this->assertSame(0, MemberBonus::where('user_id', $chain[0]->id)->count());
        $this->assertSame(15, MemberBonus::where('kind', 'referral_bonus')->count());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_missions_are_cumulative_count_unique_topup_members_and_reset_daily(): void
    {
        $children = [];
        for ($n = 0; $n < 15; $n++) {
            $child = User::factory()->create();
            Referral::create(['referrer_id' => $this->member->id, 'referred_id' => $child->id, 'points' => 0]);
            $children[] = $child;
        }
        $service = app(MemberBonusService::class);
        $service->missions($this->member, $this->workspace);
        $this->assertSame(20000, (int) MemberBonus::sum('amount'));
        for ($n = 0; $n < 15; $n++) {
            $service->topup($this->invoice($children[0], 10000));
        }
        $this->assertSame(1, $service->progress($this->member, $this->workspace)['topups']);
        $this->assertFalse(MemberBonus::where('description', '5 referral top up')->exists());
        foreach (array_slice($children, 1) as $child) {
            $service->topup($this->invoice($child, 2000000));
        }
        $this->assertSame(317000, (int) MemberBonus::where('kind', 'mission_bonus')->sum('amount'));
        $service->missions($this->member, $this->workspace);
        $this->assertSame(7, MemberBonus::where('kind', 'mission_bonus')->count());
        $this->travelTo(now()->addDay());
        $this->assertSame(0, $service->progress($this->member, $this->workspace)['registrations']);
        $this->assertSame(0, $service->progress($this->member, $this->workspace)['topups']);
        foreach (array_slice($children, 0, 5) as $child) {
            $service->topup($this->invoice($child));
        }
        $this->assertSame(8, MemberBonus::where('kind', 'mission_bonus')->count());
        $this->assertSame(342000, (int) MemberBonus::where('kind', 'mission_bonus')->sum('amount'));
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_pending_and_rejected_deposits_do_not_pay_bonus_and_approval_is_idempotent(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $child = User::factory()->create();
        Referral::create(['referrer_id' => $this->member->id, 'referred_id' => $child->id, 'points' => 0]);
        $invoice = $this->invoice($child);
        $invoice->update(['status' => 'awaiting_review', 'paid_at' => null, 'transfer_proof_path' => 'test-proof.jpg']);
        app(MemberBonusService::class)->topup($invoice);
        $this->assertSame(0, MemberBonus::count());
        Storage::disk('local')->put('test-proof.jpg', 'test');
        $service = app(ManualDepositService::class);
        $service->review($invoice, $admin, true, null);
        $service->review($invoice, $admin, true, null);
        $this->assertSame(17000, (int) MemberBonus::sum('amount'));
        $rejected = $this->invoice($child);
        $rejected->update(['status' => 'awaiting_review', 'paid_at' => null]);
        $service->review($rejected, $admin, false, null);
        $this->assertSame(17000, (int) MemberBonus::sum('amount'));
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_network_rejects_cycles(): void
    {
        $child = User::factory()->create();
        app(ReferralService::class)->attach($child, $this->member->referral_code);
        $this->expectException(ValidationException::class);
        app(ReferralService::class)->attach($this->member, $child->referral_code);
    }

    public function test_copp_pages_render_for_members(): void
    {
        $this->get('/')->assertOk()->assertSee('Master plan COPP')->assertSee('Rp5.000.000');
        $this->actingAs($this->member)->withSession(['scenario_id' => $this->workspace->id]);
        $this->get('/referrals')->assertOk()->assertSee('JARINGAN MATAHARI')->assertSee('Rp5.000');
        $this->get('/packages')->assertOk()->assertSee('COPP Luxury');
        $this->get('/investments')->assertOk();
        $this->get('/dashboard')->assertOk()->assertSee('/images/copp-banner.jpg', false);
    }

    public function test_seeding_again_does_not_create_extra_accounts_products_or_demo_transactions(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $users = User::count();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->assertSame($users, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'member@copp.test']);
        $admin = User::where('email', 'admin@copp.test')->firstOrFail();
        $this->assertSame('Didisaidi', $admin->name);
        $this->assertTrue($admin->hasRole('super-admin'));
        $this->assertNotNull($admin->email_verified_at);
        $this->post('/login', ['email' => $admin->email, 'password' => 'admin123'])
            ->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(6, RoiPackage::where('active', true)->count());
        $this->assertSame(0, Investment::count());
        $this->assertSame(0, MockPaymentInvoice::count());
        $this->assertSame(0, SimulationScenario::where('mode', 'simulation')->count());
    }
}
