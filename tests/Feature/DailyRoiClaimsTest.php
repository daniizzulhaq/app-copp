<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\RoiObligation;
use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Services\DailyRoiClaimService;
use App\Services\InvestmentService;
use App\Services\LedgerIntegrityService;
use App\Services\LedgerService;
use App\Services\LocalOperationsService;
use App\Services\SimulatedDistributionService;
use App\Services\WalletService;
use App\Services\WithdrawalSimulationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DailyRoiClaimsTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;
    private User $member;
    private Investment $investment;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-29 10:00:00', 'Asia/Jakarta'));
        $this->workspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running', 'start_date' => today()]);
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $this->member->forceFill(['bank_name' => 'Bank Test', 'bank_account_number' => '1234567890', 'bank_account_holder' => $this->member->name])->save();
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, 3000000, 'test-deposit:'.$this->member->id);
        $package = RoiPackage::factory()->create(['daily_rate_bps' => 100, 'daily_roi_cap' => 700]);
        $this->investment = app(InvestmentService::class)->open($this->member, $this->workspace, $package, 100000, (string) Str::uuid());
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00', 'Asia/Jakarta'));
    }

    private function todayClaim(): RoiObligation
    {
        app(DailyRoiClaimService::class)->prepare($this->workspace);

        return $this->investment->obligations()->whereDate('due_date', today())->firstOrFail();
    }

    private function balance(): int
    {
        return app(WalletService::class)->get($this->member, $this->workspace)->balance();
    }

    private function assertLedgerValid(): void
    {
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_scheduler_prepares_but_never_credits_daily_roi(): void
    {
        $before = $this->balance();
        $run = app(LocalOperationsService::class)->process($this->workspace);
        app(SimulatedDistributionService::class)->pay($this->workspace, today()->toDateString());
        app(LocalOperationsService::class)->process($this->workspace);
        $this->assertSame($before, $this->balance());
        $this->assertSame(700, $this->todayClaim()->amount);
        $this->assertSame(0, $this->todayClaim()->paid_amount);
        $this->assertSame('running', $run->status);
        $this->assertLedgerValid();
    }

    public function test_authenticated_claim_credits_once_even_when_repeated(): void
    {
        $claim = $this->todayClaim();
        $before = $this->balance();
        $this->actingAs($this->member)->post(route('roi.claim', $claim))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('roi.claim', $claim))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before + 700, $this->balance());
        $this->assertSame(1, $claim->distributions()->count());
        $this->assertNotNull($claim->fresh()->claimed_at);
        $this->assertLedgerValid();
    }

    public function test_member_cannot_claim_another_members_roi(): void
    {
        $claim = $this->todayClaim();
        $other = User::factory()->create();
        $other->assignRole('member');
        $this->actingAs($other)->post(route('roi.claim', $claim))->assertForbidden();
        $this->assertSame(0, $claim->fresh()->paid_amount);
    }

    public function test_guests_and_staff_cannot_claim_member_roi(): void
    {
        $claim = $this->todayClaim();
        $this->post(route('roi.claim', $claim))->assertRedirect('/login');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin)->post(route('roi.claim', $claim))->assertForbidden();
    }

    public function test_midnight_expires_old_claim_without_touching_wallet(): void
    {
        $claim = $this->todayClaim();
        $before = $this->balance();
        $this->travelTo(Carbon::parse('2026-10-31 00:00:00', 'Asia/Jakarta'));
        $this->actingAs($this->member)->post(route('roi.claim', $claim))->assertSessionHasErrors('claim');
        $this->assertSame('expired', $claim->fresh()->status);
        $this->assertSame(700, $claim->fresh()->expired_amount);
        $this->assertSame(0, $claim->fresh()->remaining());
        $this->assertSame($before, $this->balance());
        $this->assertSame(700, app(LedgerService::class)->balance($this->workspace, 'roi-payable'));
        app(DailyRoiClaimService::class)->prepare($this->workspace);
        $this->assertSame(1, $this->workspace->transactions()->where('kind', 'roi_expired')->count());
        $this->assertLedgerValid();
    }

    public function test_claim_is_allowed_just_before_midnight(): void
    {
        $claim = $this->todayClaim();
        $this->travelTo(Carbon::parse('2026-10-30 23:59:59', 'Asia/Jakarta'));
        $this->actingAs($this->member)->post(route('roi.claim', $claim))->assertSessionHasNoErrors();
        $this->assertSame(700, $claim->fresh()->paid_amount);
    }

    public function test_missed_scheduler_days_expire_and_only_today_is_claimable(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'Asia/Jakarta'));
        app(DailyRoiClaimService::class)->prepare($this->workspace);
        $this->assertSame(4, $this->investment->obligations()->where('status', 'expired')->count());
        $this->assertSame(2800, (int) $this->investment->obligations()->sum('expired_amount'));
        $this->assertSame(700, $this->todayClaim()->remaining());
        $this->assertLedgerValid();
    }

    public function test_daily_cap_is_snapshotted_when_product_is_activated(): void
    {
        $this->investment->package->update(['daily_roi_cap' => 200]);
        $this->assertSame(700, $this->todayClaim()->amount);
        $this->assertSame(21000, $this->investment->promised());
    }

    public function test_daily_claim_credits_full_amount_with_zero_cash(): void
    {
        $claim = $this->todayClaim();
        $ledger = app(LedgerService::class);
        $this->assertSame(0, $ledger->balance($this->workspace, 'cash'));
        $before = $this->balance();
        $this->actingAs($this->member)->post(route('roi.claim', $claim))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('roi.claim', $claim))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before + 700, $this->balance());
        $this->assertSame(700, $claim->fresh()->paid_amount);
        $this->assertSame(1, $claim->distributions()->count());
        $this->assertSame(0, $ledger->balance($this->workspace, 'cash'));
        $this->assertLedgerValid();
    }

    public function test_withdrawal_minimum_is_enforced_in_service_and_http(): void
    {
        $key = (string) Str::uuid();
        $this->actingAs($this->member)->withSession(['scenario_id' => $this->workspace->id])
            ->post('/withdrawals', ['amount' => 49999, 'method' => 'bank_transfer', 'request_key' => $key])->assertSessionHasErrors('amount');
        $this->expectException(ValidationException::class);
        app(WithdrawalSimulationService::class)->request($this->member, $this->workspace, 49999, 'bank_transfer', null, $key);
    }

    public function test_approved_withdrawal_deducts_ten_percent_and_pays_net_once(): void
    {
        $before = $this->balance();
        $service = app(WithdrawalSimulationService::class);
        $withdrawal = $service->request($this->member, $this->workspace, 50000, 'bank_transfer', null, (string) Str::uuid());
        $this->assertSame(5000, $withdrawal->admin_fee);
        $this->assertSame(45000, $withdrawal->netAmount());
        $this->assertSame($before - 50000, $this->balance());
        $service->review($withdrawal, true);
        $service->review($withdrawal, true);
        $this->assertDatabaseHas('simulated_payouts', ['withdrawal_request_id' => $withdrawal->id, 'amount' => 45000]);
        $this->assertSame(5000, app(LedgerService::class)->balance($this->workspace, 'withdrawal-fees'));
        $this->assertLedgerValid();
    }

    public function test_rejected_withdrawal_refunds_full_gross_amount_without_fee(): void
    {
        $before = $this->balance();
        $service = app(WithdrawalSimulationService::class);
        $withdrawal = $service->request($this->member, $this->workspace, 50000, 'bank_transfer', null, (string) Str::uuid());
        $service->review($withdrawal, false);
        $this->assertSame($before, $this->balance());
        $this->assertSame(0, app(LedgerService::class)->balance($this->workspace, 'withdrawal-fees'));
        $this->assertLedgerValid();
    }

    public function test_dashboard_and_portfolio_show_daily_claim_action(): void
    {
        $this->actingAs($this->member)->withSession(['scenario_id' => $this->workspace->id])
            ->get('/dashboard')->assertOk()->assertSee('Klaim ROI hari ini');
        $this->get('/investments')->assertOk()->assertSee('Klaim ROI hari ini');
        $this->get('/investments/'.$this->investment->id)->assertOk()->assertSee('Klaim ROI hari ini');
    }

    public function test_simulation_distribution_keeps_its_existing_automatic_behavior(): void
    {
        $this->workspace->update(['mode' => 'simulation']);
        app(LedgerService::class)->post($this->workspace, 'simulation-funds', 'seed', 'Simulation initial funds',
            [['cash', 'debit', 100000], ['seed-equity', 'credit', 100000]]);
        app(\App\Services\ROIObligationService::class)->accrue($this->workspace, today());
        $before = $this->balance();
        $paid = app(SimulatedDistributionService::class)->pay($this->workspace, today()->toDateString());
        $this->assertSame(700, $paid);
        $this->assertSame($before + 700, $this->balance());
        $this->assertLedgerValid();
    }

    public function test_matured_product_completes_after_unclaimed_roi_expires(): void
    {
        $ledger = app(LedgerService::class);
        $ledger->post($this->workspace, 'test-seed', 'seed', 'Test cadangan modal', [['cash', 'debit', 100000], ['seed-equity', 'credit', 100000]]);
        $this->travelTo(Carbon::parse('2026-11-28 10:00:00', 'Asia/Jakarta'));
        app(LocalOperationsService::class)->process($this->workspace);
        $this->assertSame('active', $this->investment->fresh()->status);
        $this->assertSame(100000, $this->investment->fresh()->principal_returned_amount);
        $this->travelTo(Carbon::parse('2026-11-29 00:00:00', 'Asia/Jakarta'));
        app(LocalOperationsService::class)->process($this->workspace);
        $this->assertSame('completed', $this->investment->fresh()->status);
        $this->assertSame(21000, (int) $this->investment->obligations()->sum('expired_amount'));
        $this->assertSame(0, app(LedgerService::class)->balance($this->workspace, 'roi-payable'));
        $this->assertLedgerValid();
    }

    public function test_archived_workspace_rejects_claims(): void
    {
        $claim = $this->todayClaim();
        $this->workspace->update(['status' => 'archived']);
        $before = $this->balance();
        $this->actingAs($this->member)->post(route('roi.claim', $claim))->assertSessionHasErrors('claim');
        $this->assertSame($before, $this->balance());
    }

    public function test_old_withdrawal_retains_original_fee_free_payout(): void
    {
        $service = app(WithdrawalSimulationService::class);
        $withdrawal = $service->request($this->member, $this->workspace, 50000, 'bank_transfer', null, (string) Str::uuid());
        $withdrawal->update(['admin_fee' => 0]);
        $service->review($withdrawal, true);
        $this->assertDatabaseHas('simulated_payouts', ['withdrawal_request_id' => $withdrawal->id, 'amount' => 50000]);
        $this->assertLedgerValid();
    }
}
