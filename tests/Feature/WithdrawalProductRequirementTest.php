<?php

namespace Tests\Feature;

use App\Models\Investment;
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

class WithdrawalProductRequirementTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->workspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running', 'start_date' => today()]);
        SystemSetting::updateOrCreate(['key' => 'local_workspace'], ['value' => (string) $this->workspace->id]);
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $this->member->forceFill(['bank_name' => 'Bank Test', 'bank_account_number' => '1234567890', 'bank_account_holder' => $this->member->name])->save();
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, 200000, 'wd-product-deposit:'.$this->member->id);
        $this->actingAs($this->member);
    }

    private function purchase(?User $user = null, ?SimulationScenario $workspace = null): Investment
    {
        return app(InvestmentService::class)->open($user ?? $this->member, $workspace ?? $this->workspace,
            RoiPackage::factory()->create(), 100000, (string) Str::uuid());
    }

    private function submitWithdrawal()
    {
        return $this->post('/withdrawals', ['amount' => 50000, 'method' => 'bank_transfer', 'request_key' => (string) Str::uuid()]);
    }

    public function test_member_without_product_sees_requirement_and_disabled_button(): void
    {
        $response = $this->get('/withdrawals')->assertOk()
            ->assertSee('Beli minimal satu produk terlebih dahulu sebelum mengajukan penarikan.')
            ->assertSee('Lihat produk');
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>Ajukan penarikan/', $response->getContent());
    }

    public function test_direct_http_request_without_product_is_rejected_without_holding_balance(): void
    {
        $this->submitWithdrawal()->assertSessionHasErrors('product');
        $this->assertDatabaseMissing('withdrawal_requests', ['user_id' => $this->member->id]);
        $wallet = app(WalletService::class)->get($this->member, $this->workspace);
        $this->assertSame(200000, $wallet->balance());
        $this->assertSame(0, $wallet->balance('pending'));
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_service_also_rejects_withdrawal_without_product(): void
    {
        try {
            app(WithdrawalSimulationService::class)->request($this->member, $this->workspace, 50000, 'bank_transfer', null, (string) Str::uuid());
            $this->fail('Withdrawal should require a product purchase.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product', $exception->errors());
        }
        $this->assertDatabaseMissing('withdrawal_requests', ['user_id' => $this->member->id]);
    }

    public function test_another_members_product_does_not_unlock_withdrawal(): void
    {
        $other = User::factory()->create();
        app(WalletService::class)->creditDeposit($other, $this->workspace, 100000, 'wd-other:'.$other->id);
        $this->purchase($other);
        $this->submitWithdrawal()->assertSessionHasErrors('product');
    }

    public function test_product_in_another_workspace_does_not_unlock_withdrawal(): void
    {
        $otherWorkspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running']);
        app(WalletService::class)->creditDeposit($this->member, $otherWorkspace, 100000, 'wd-other-workspace:'.$otherWorkspace->id);
        $this->purchase(workspace: $otherWorkspace);
        $this->submitWithdrawal()->assertSessionHasErrors('product');
    }

    public function test_pending_product_does_not_unlock_withdrawal(): void
    {
        $this->purchase()->update(['status' => 'pending']);
        $this->submitWithdrawal()->assertSessionHasErrors('product');
    }

    public static function purchasedProductStatuses(): array
    {
        return [['active'], ['completed'], ['suspended'], ['defaulted']];
    }

    #[DataProvider('purchasedProductStatuses')]
    public function test_purchased_product_unlocks_withdrawal_including_after_completion(string $status): void
    {
        $this->purchase()->update(['status' => $status]);
        $response = $this->get('/withdrawals')->assertOk()
            ->assertDontSee('Beli minimal satu produk terlebih dahulu sebelum mengajukan penarikan.');
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*disabled[^>]*>Ajukan penarikan/', $response->getContent());
        $this->submitWithdrawal()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('withdrawal_requests', ['user_id' => $this->member->id, 'amount' => 50000, 'status' => 'pending']);
        $wallet = app(WalletService::class)->get($this->member, $this->workspace);
        $this->assertSame(50000, $wallet->balance());
        $this->assertSame(50000, $wallet->balance('pending'));
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }
}
