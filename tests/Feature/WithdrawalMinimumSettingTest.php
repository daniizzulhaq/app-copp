<?php

namespace Tests\Feature;

use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\LedgerIntegrityService;
use App\Services\WalletService;
use App\Services\WithdrawalSimulationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WithdrawalMinimumSettingTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $member;

    private SimulationScenario $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        SystemSetting::whereKey('minimum_withdrawal_amount')->delete();
        $this->workspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running', 'start_date' => today()]);
        SystemSetting::updateOrCreate(['key' => 'local_workspace'], ['value' => (string) $this->workspace->id]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->member = User::factory()->create([
            'bank_name' => 'Bank Test', 'bank_account_number' => '1234567890', 'bank_account_holder' => 'Member Test',
        ]);
        $this->member->assignRole('member');
    }

    private function saveMinimum(int $amount): void
    {
        $this->actingAs($this->admin)->put('/settings', [
            'default_scenario' => $this->workspace->id, 'minimum_withdrawal_amount' => $amount,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function fundMember(): void
    {
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, 500000, 'wd-minimum:'.$this->member->id);
        app(InvestmentService::class)->open($this->member, $this->workspace, RoiPackage::factory()->create(), 100000, (string) Str::uuid());
    }

    public function test_default_is_shown_and_admin_can_save_and_preserve_minimum(): void
    {
        $this->assertSame(50000, SystemSetting::minimumWithdrawalAmount());
        $this->actingAs($this->admin)->get('/settings')->assertOk()
            ->assertSee('Minimal WD (Rp)')->assertSee('value="50000"', false);
        $this->saveMinimum(75000);
        $this->assertDatabaseHas('system_settings', ['key' => 'minimum_withdrawal_amount', 'value' => '75000']);
        $this->get('/settings')->assertOk()->assertSee('value="75000"', false);
        $this->put('/settings', ['default_scenario' => $this->workspace->id])->assertSessionHasNoErrors();
        $this->assertSame(75000, SystemSetting::minimumWithdrawalAmount());
        $this->actingAs($this->member)->get('/withdrawals')->assertOk()
            ->assertSee('min="75000"', false)->assertSee('Minimum WD Rp75.000.')
            ->assertSee('admin Rp7.500, diterima Rp67.500.');
    }

    public static function invalidMinimums(): array
    {
        return [[null], [''], [0], [-1], [1000000001], ['75000.5'], ['abc'], [[75000]]];
    }

    #[DataProvider('invalidMinimums')]
    public function test_invalid_setting_is_rejected_without_changing_saved_value(mixed $amount): void
    {
        $this->saveMinimum(75000);
        $this->put('/settings', [
            'default_scenario' => $this->workspace->id, 'minimum_withdrawal_amount' => $amount,
        ])->assertSessionHasErrors('minimum_withdrawal_amount');
        $this->assertSame(75000, SystemSetting::minimumWithdrawalAmount());
    }

    public function test_guests_and_members_cannot_change_minimum(): void
    {
        $data = ['default_scenario' => $this->workspace->id, 'minimum_withdrawal_amount' => 10000];
        $this->put('/settings', $data)->assertRedirect('/login');
        $this->actingAs($this->member)->get('/settings')->assertForbidden();
        $this->put('/settings', $data)->assertForbidden();
        $this->assertSame(50000, SystemSetting::minimumWithdrawalAmount());
    }

    public static function configuredMinimums(): array
    {
        return [[25000], [75000]];
    }

    #[DataProvider('configuredMinimums')]
    public function test_http_and_service_enforce_configured_minimum_without_holding_rejected_balance(int $minimum): void
    {
        $this->saveMinimum($minimum);
        $this->fundMember();
        $this->actingAs($this->member)->post('/withdrawals', [
            'amount' => $minimum - 1, 'method' => 'bank_transfer', 'request_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('amount');
        try {
            app(WithdrawalSimulationService::class)->request($this->member, $this->workspace, $minimum - 1, 'bank_transfer', null, (string) Str::uuid());
            $this->fail('Service should reject amounts below the configured minimum.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        $wallet = app(WalletService::class)->get($this->member, $this->workspace);
        $this->assertSame(400000, $wallet->balance());
        $this->assertSame(0, $wallet->balance('pending'));
        $this->assertDatabaseMissing('withdrawal_requests', ['user_id' => $this->member->id]);
        $this->post('/withdrawals', [
            'amount' => $minimum, 'method' => 'bank_transfer', 'request_key' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('withdrawal_requests', ['user_id' => $this->member->id, 'amount' => $minimum, 'status' => 'pending']);
        $this->assertSame(400000 - $minimum, $wallet->balance());
        $this->assertSame($minimum, $wallet->balance('pending'));
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_changing_minimum_does_not_prevent_review_of_pending_withdrawals(): void
    {
        $this->fundMember();
        $service = app(WithdrawalSimulationService::class);
        $key = (string) Str::uuid();
        $withdrawal = $service->request($this->member, $this->workspace, 50000, 'bank_transfer', null, $key);
        $this->saveMinimum(100000);
        $this->assertSame($withdrawal->id, $service->request($this->member, $this->workspace, 50000, 'bank_transfer', null, $key)->id);
        $service->review($withdrawal, true, $this->admin);
        $this->assertDatabaseHas('withdrawal_requests', ['id' => $withdrawal->id, 'status' => 'approved', 'amount' => 50000]);
        $this->assertDatabaseHas('simulated_payouts', ['withdrawal_request_id' => $withdrawal->id, 'amount' => 45000]);
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }
}
