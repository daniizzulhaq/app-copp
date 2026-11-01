<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\LedgerIntegrityService;
use App\Services\LedgerService;
use App\Services\LocalOperationsService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrincipalReturnOnAccessTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;
    private User $member;
    private Investment $investment;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'Asia/Jakarta'));
        $this->workspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running', 'start_date' => today()]);
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $this->member->forceFill(['bank_name' => 'Bank Test', 'bank_account_number' => '1234567890', 'bank_account_holder' => $this->member->name])->save();
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, 100000, 'principal-test:'.$this->member->id);
        $package = RoiPackage::factory()->create(['duration_days' => 35]);
        $this->investment = app(InvestmentService::class)->open($this->member, $this->workspace, $package, 100000, (string) Str::uuid());
        $this->actingAs($this->member)->withSession(['scenario_id' => $this->workspace->id]);
    }

    private function balance(): int
    {
        return app(WalletService::class)->get($this->member, $this->workspace)->balance();
    }

    private function atMaturity(int $daysAfter = 0): void
    {
        $this->travelTo($this->investment->ends_at->copy()->addDays($daysAfter)->startOfDay());
    }

    public static function pages(): array
    {
        return [['/dashboard'], ['/wallet'], ['/investments'], ['/investments/{id}'], ['/withdrawals']];
    }

    #[DataProvider('pages')]
    public function test_first_page_access_returns_matured_principal_once_without_scheduler(string $page): void
    {
        $this->atMaturity();
        $this->assertSame(0, $this->balance());
        $page = str_replace('{id}', (string) $this->investment->id, $page);
        $response = $this->get($page)->assertOk();
        $this->assertSame(100000, $this->balance());
        $this->assertSame(100000, $this->investment->fresh()->principal_returned_amount);
        if ($page === '/investments/'.$this->investment->id) {
            $response->assertSee('Sisa modal</dt><dd>Rp0', false);
        }
        $this->get($page)->assertOk();
        // The optional scheduler remains safe after processing on a page request.
        app(LocalOperationsService::class)->process($this->workspace);
        $this->assertSame(100000, $this->balance());
        $this->assertSame(1, $this->workspace->transactions()->where('kind', 'principal_returned')->count());
        $this->assertSame(0, (int) $this->investment->obligations()->sum('paid_amount'));
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_no_early_return_and_snapshot_maturity_is_used_instead_of_thirty_days(): void
    {
        // Editing the product must not move the existing portfolio's maturity date.
        $this->investment->package->update(['duration_days' => 30]);
        $this->travelTo($this->investment->starts_at->copy()->addDays(30));
        $this->get('/wallet')->assertOk();
        $this->assertSame(0, $this->balance());
        $this->travelTo($this->investment->ends_at->copy()->subSecond());
        $this->get('/wallet')->assertOk();
        $this->assertSame(0, $this->balance());
        $this->atMaturity();
        $this->get('/wallet')->assertOk();
        $this->assertSame(100000, $this->balance());
    }

    public function test_late_access_returns_principal_and_expires_missed_roi_without_crediting_it(): void
    {
        $this->atMaturity(20);
        $this->get('/wallet')->assertOk();
        $this->assertSame(100000, $this->balance());
        $this->assertSame('completed', $this->investment->fresh()->status);
        $this->assertSame(35000, (int) $this->investment->obligations()->sum('expired_amount'));
        $this->assertSame(0, (int) $this->investment->obligations()->sum('paid_amount'));
        $this->assertSame(today()->toDateString(), $this->workspace->transactions()->where('kind', 'principal_returned')->firstOrFail()->effective_date->toDateString());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_legacy_partial_return_is_completed_in_full_without_cash(): void
    {
        $ledger = app(LedgerService::class);
        $this->workspace->update(['mode' => 'simulation']);
        $ledger->post($this->workspace, 'legacy-funds', 'seed', 'Legacy partial return',
            [['cash', 'debit', 40000], ['seed-equity', 'credit', 40000]]);
        $this->atMaturity(1);
        app(\App\Services\PrincipalReturnService::class)->pay($this->workspace, today()->toDateString());
        $this->assertSame(40000, $this->balance());
        $this->assertSame('defaulted', $this->investment->fresh()->status);
        $this->assertSame(0, $ledger->balance($this->workspace, 'cash'));
        $this->workspace->update(['mode' => 'local']);
        $this->get('/wallet')->assertOk();
        $this->get('/wallet')->assertOk();
        $this->assertSame(100000, $this->balance());
        $this->assertSame('completed', $this->investment->fresh()->status);
        $this->assertSame(2, $this->workspace->transactions()->where('kind', 'principal_returned')->count());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_withdrawal_can_use_matured_principal_without_visiting_a_page_first(): void
    {
        $this->atMaturity();
        $this->post('/withdrawals', ['amount' => 50000, 'method' => 'bank_transfer', 'request_key' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(50000, $this->balance());
        $this->assertSame(100000, $this->investment->fresh()->principal_returned_amount);
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_repurchase_can_use_returned_principal_without_scheduler(): void
    {
        $this->atMaturity();
        $payload = ['amount' => 100000, 'roi_package_id' => $this->investment->roi_package_id, 'request_key' => (string) Str::uuid()];
        $this->post('/investments', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/investments', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, $this->balance());
        $this->assertSame(2, $this->member->investments()->count());
        $this->assertSame(1, $this->workspace->transactions()->where('kind', 'principal_returned')->count());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_archived_and_simulation_workspaces_do_not_automatically_return_principal_on_access(): void
    {
        $this->atMaturity();
        $this->workspace->update(['status' => 'archived']);
        $this->get('/wallet')->assertOk();
        $this->assertSame(0, $this->balance());
        $this->workspace->update(['status' => 'running', 'mode' => 'simulation']);
        $this->get('/wallet')->assertOk();
        $this->assertSame(0, $this->balance());
    }

    public function test_saved_million_rupiah_principal_returns_in_full_even_if_the_product_price_changes(): void
    {
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, 1000000, 'million-deposit');
        $package = RoiPackage::factory()->create(['minimum_amount' => 1000000, 'duration_days' => 2]);
        $investment = app(InvestmentService::class)->open($this->member, $this->workspace, $package, 1000000, (string) Str::uuid());
        $package->update(['minimum_amount' => 5000000, 'duration_days' => 90]);
        $this->travelTo($investment->ends_at->copy()->startOfDay());
        $this->get('/wallet')->assertOk();
        $this->get('/wallet')->assertOk();
        $this->assertSame(1000000, $this->balance());
        $this->assertSame(1000000, $investment->fresh()->principal_returned_amount);
        $this->assertSame(0, $this->investment->fresh()->principal_returned_amount);
        $this->assertSame(0, app(LedgerService::class)->balance($this->workspace, 'cash'));
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_full_roi_and_principal_can_be_credited_without_any_cash_funding(): void
    {
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, 100000, 'roi-lifetime-deposit');
        $package = RoiPackage::factory()->create(['daily_rate_bps' => 500, 'total_roi_bps' => 21000, 'duration_days' => 42]);
        $investment = app(InvestmentService::class)->open($this->member, $this->workspace, $package, 100000, (string) Str::uuid());
        for ($day = 1; $day <= 42; $day++) {
            $this->travelTo($investment->starts_at->copy()->addDays($day)->setTime(10, 0));
            $claims = app(\App\Services\DailyRoiClaimService::class)->forMember($this->workspace, $this->member);
            $claim = $claims->firstWhere('investment_id', $investment->id);
            app(\App\Services\DailyRoiClaimService::class)->claim($this->member, $claim);
        }
        $this->get('/wallet')->assertOk();
        // Includes the original setup investment's separate Rp100.000 maturity.
        $this->assertSame(410000, $this->balance());
        $this->assertSame(210000, (int) $investment->obligations()->sum('paid_amount'));
        $this->assertSame(100000, $investment->fresh()->principal_returned_amount);
        $this->assertSame('completed', $investment->fresh()->status);
        $this->assertSame(0, app(LedgerService::class)->balance($this->workspace, 'cash'));
        $metrics = app(\App\Services\DashboardService::class)->data($this->workspace, '2026-11-01', today()->toDateString(), $this->member->id)['metrics'];
        $this->assertSame(210000, $metrics['paid']);
        $this->assertSame(200000, $metrics['principal_returned']);
        $run = app(LocalOperationsService::class)->process($this->workspace);
        $this->assertSame(5000, $run->roi_paid);
        $this->assertSame(100000, $run->principal_returned);
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_admin_pages_and_exports_show_wallet_balances_without_cash_controls(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        \App\Models\SystemSetting::updateOrCreate(['key' => 'local_workspace'], ['value' => $this->workspace->id]);
        $this->actingAs($admin);
        foreach (['/dashboard', '/operations', '/reports', '/ledger', '/analysis-reports?type=cash', '/analysis-reports?type=runs'] as $page) {
            $this->get($page)->assertOk()->assertDontSee('Kas bebas')->assertDontSee('Kas tersedia')->assertDontSee('Funding gap');
        }
        $this->get('/scenarios/'.$this->workspace->id)->assertRedirect('/operations');
        $csv = $this->get('/reports?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Saldo web member', $csv);
        $this->assertStringNotContainsString('Funding gap', $csv);
        $this->assertStringNotContainsString('Kas bebas', $csv);
        $this->get('/reports?format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_own_wallet_balance_is_still_required_to_record_a_product_purchase(): void
    {
        $count = $this->member->investments()->count();
        $this->post('/investments', ['amount' => 100000, 'roi_package_id' => $this->investment->roi_package_id, 'request_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('amount');
        $this->assertSame($count, $this->member->investments()->count());
        $this->assertSame(0, $this->balance());
    }
}
