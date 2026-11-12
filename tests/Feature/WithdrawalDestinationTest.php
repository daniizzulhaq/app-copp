<?php

namespace Tests\Feature;

use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\InvestmentService;
use App\Services\LedgerIntegrityService;
use App\Services\WalletService;
use App\Services\WithdrawalSimulationService;
use App\Support\WithdrawalDestination;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WithdrawalDestinationTest extends TestCase
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
        SystemSetting::updateOrCreate(['key' => 'minimum_withdrawal_amount'], ['value' => '50000']);
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, 500000, 'wd-destination:'.$this->member->id);
        app(InvestmentService::class)->open($this->member, $this->workspace, RoiPackage::factory()->create(), 100000, (string) Str::uuid());
        $this->actingAs($this->member);
    }

    private function destination(string $provider = 'DANA'): array
    {
        return ['bank_name' => $provider, 'bank_account_number' => '081234567890', 'bank_account_holder' => 'Pemilik Tujuan'];
    }

    private function submit(array $destination, ?string $key = null)
    {
        return $this->post('/withdrawals', array_merge([
            'amount' => 50000, 'method' => 'bank_transfer', 'request_key' => $key ?? (string) Str::uuid(),
        ], $destination));
    }

    public function test_grouped_picker_is_inside_withdrawal_form_and_does_not_require_a_saved_account(): void
    {
        $response = $this->get('/withdrawals')->assertOk()
            ->assertSee('Bank / E-Wallet tujuan')->assertSee('E-WALLET POPULER')
            ->assertSee('BANK NASIONAL &amp; SWASTA', false)
            ->assertSee('Nomor rekening / nomor HP e-wallet');
        foreach (WithdrawalDestination::providers() as $provider) {
            $response->assertSee('value="'.$provider.'"', false);
        }
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*disabled[^>]*>Ajukan penarikan/', $response->getContent());
        $this->assertMatchesRegularExpression('/<form[^>]*action="\/withdrawals"[^>]*>[\s\S]*?<select name="bank_name"/', $response->getContent());
        $this->get('/profile')->assertOk()->assertSee('Bank / E-Wallet saya')->assertSee('value="DANA"', false);
    }

    public static function providers(): array
    {
        return array_map(fn ($provider) => [$provider], WithdrawalDestination::providers());
    }

    #[DataProvider('providers')]
    public function test_bank_and_wallet_choices_are_saved_on_each_request(string $provider): void
    {
        $destination = $this->destination($provider);
        $this->submit(array_merge($destination, ['bank_account_number' => '0812 3456-7890']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('withdrawal_requests', array_merge($destination, [
            'user_id' => $this->member->id, 'amount' => 50000, 'admin_fee' => 5000, 'status' => 'pending',
        ]));
        $this->assertNull($this->member->fresh()->bank_name);
        $wallet = app(WalletService::class)->get($this->member, $this->workspace);
        $this->assertSame(350000, $wallet->balance());
        $this->assertSame(50000, $wallet->balance('pending'));
    }

    public function test_profile_changes_do_not_rewrite_submitted_destination_and_admin_can_complete_wallet_transfer(): void
    {
        $this->submit($this->destination())->assertSessionHasNoErrors();
        $withdrawal = WithdrawalRequest::where('user_id', $this->member->id)->firstOrFail();
        $this->put('/profile/bank-account', array_merge($this->destination('Bank BRI'), ['bank_account_number' => '001234567890']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Bank BRI', $this->member->fresh()->bank_name);
        $this->assertSame('DANA', $withdrawal->fresh()->bank_name);
        $this->assertSame('081234567890', $withdrawal->fresh()->bank_account_number);
        $this->get('/withdrawals')->assertOk()->assertSee('Transfer e-wallet')->assertSee('DANA');

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin)->get('/withdrawals')->assertOk()
            ->assertSee('DANA')->assertSee('081234567890')->assertSee('Pemilik Tujuan')->assertSee('Transfer e-wallet');
        $this->patch('/withdrawals/'.$withdrawal->id, ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('withdrawal_requests', ['id' => $withdrawal->id, 'status' => 'approved', 'bank_name' => 'DANA']);
        $this->assertDatabaseHas('simulated_payouts', ['withdrawal_request_id' => $withdrawal->id, 'amount' => 45000]);
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_saved_wallet_is_prefilled_and_remains_usable_for_requests_without_destination_fields(): void
    {
        $this->put('/profile/bank-account', $this->destination('GoPay'))->assertSessionHasNoErrors();
        $this->actingAs($this->member->refresh());
        $this->get('/withdrawals')->assertOk()->assertSee('value="GoPay" selected', false)
            ->assertSee('value="081234567890"', false);
        $this->submit([])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('withdrawal_requests', ['user_id' => $this->member->id, 'bank_name' => 'GoPay', 'bank_account_number' => '081234567890']);
    }

    public static function invalidDestinations(): array
    {
        return [
            'missing provider' => [['bank_name' => ''], 'bank_name'],
            'unknown provider' => [['bank_name' => 'Unlisted Provider'], 'bank_name'],
            'provider array' => [['bank_name' => ['DANA']], 'bank_name'],
            'missing number' => [['bank_account_number' => ''], 'bank_account_number'],
            'number array' => [['bank_account_number' => ['081234567890']], 'bank_account_number'],
            'letters in number' => [['bank_account_number' => '0812abc67890'], 'bank_account_number'],
            'short number' => [['bank_account_number' => '1234'], 'bank_account_number'],
            'missing holder' => [['bank_account_holder' => ''], 'bank_account_holder'],
        ];
    }

    #[DataProvider('invalidDestinations')]
    public function test_invalid_destination_is_rejected_without_holding_balance(array $invalid, string $field): void
    {
        $this->submit(array_merge($this->destination(), $invalid))->assertSessionHasErrors($field);
        $this->assertDatabaseMissing('withdrawal_requests', ['user_id' => $this->member->id]);
        $wallet = app(WalletService::class)->get($this->member, $this->workspace);
        $this->assertSame(400000, $wallet->balance());
        $this->assertSame(0, $wallet->balance('pending'));
    }

    public function test_partial_destination_does_not_silently_use_saved_account(): void
    {
        $this->member->forceFill($this->destination('Bank BCA'))->save();
        $this->submit(['bank_name' => 'OVO'])->assertSessionHasErrors(['bank_account_number', 'bank_account_holder']);
        $this->assertDatabaseMissing('withdrawal_requests', ['user_id' => $this->member->id]);
    }

    public function test_retry_is_idempotent_but_cannot_change_destination(): void
    {
        $key = (string) Str::uuid();
        $this->submit($this->destination(), $key)->assertSessionHasNoErrors();
        $this->submit($this->destination(), $key)->assertSessionHasNoErrors();
        $this->submit($this->destination('OVO'), $key)->assertSessionHasErrors('request_key');
        $this->assertSame(1, WithdrawalRequest::where('user_id', $this->member->id)->count());
        $wallet = app(WalletService::class)->get($this->member, $this->workspace);
        $this->assertSame(350000, $wallet->balance());
        $this->assertSame(50000, $wallet->balance('pending'));
    }

    public function test_direct_service_calls_also_validate_destination(): void
    {
        try {
            app(WithdrawalSimulationService::class)->request($this->member, $this->workspace, 50000,
                'bank_transfer', null, (string) Str::uuid(), $this->destination('Unlisted Provider'));
            $this->fail('Invalid destinations should be rejected by the service.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bank_name', $exception->errors());
        }
        $this->assertDatabaseMissing('withdrawal_requests', ['user_id' => $this->member->id]);
    }

    public function test_old_saved_bank_names_remain_available_without_accepting_other_unknown_providers(): void
    {
        $this->member->forceFill($this->destination('BCA lama'))->save();
        $this->get('/withdrawals')->assertOk()->assertSee('value="BCA lama" selected', false);
        $this->submit($this->destination('BCA lama'))->assertSessionHasNoErrors();
        $this->put('/profile/bank-account', $this->destination('Unlisted Provider'))->assertSessionHasErrors('bank_name');
    }
}
