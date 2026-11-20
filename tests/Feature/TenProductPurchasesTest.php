<?php

namespace Tests\Feature;

use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\LedgerIntegrityService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TenProductPurchasesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_set_ten_purchases_and_larger_limits_are_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin)->get('/packages/create')->assertOk()
            ->assertSee('max="10"', false);
        $data = [
            'name' => 'Plan gotong royong', 'minimum_amount' => 100000,
            'daily_rate_bps' => 500, 'duration_days' => 42, 'total_roi_bps' => 21000,
            'purchase_limit' => 10, 'fixed_amount' => true, 'active' => true,
        ];
        $this->post('/packages', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('roi_packages', ['name' => $data['name'], 'purchase_limit' => 10]);
        $this->post('/packages', array_replace($data, ['purchase_limit' => 11]))
            ->assertSessionHasErrors('purchase_limit');
    }

    public function test_tenth_purchase_succeeds_retries_do_not_count_and_next_purchase_fails(): void
    {
        Notification::fake();
        $member = User::factory()->create();
        $workspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running']);
        $package = RoiPackage::factory()->create(['purchase_limit' => 10, 'fixed_amount' => true]);
        $wallets = app(WalletService::class);
        $wallets->creditDeposit($member, $workspace, 1100000, 'ten-purchases:'.$member->id);
        $service = app(InvestmentService::class);
        for ($n = 0; $n < 10; $n++) {
            $key = (string) Str::uuid();
            $investment = $service->open($member, $workspace, $package, 100000, $key);
        }
        $this->assertSame($investment->id, $service->open($member, $workspace, $package, 100000, $key)->id);
        $investment->update(['status' => 'completed']);
        try {
            $service->open($member, $workspace, $package, 100000, (string) Str::uuid());
            $this->fail('Purchase 11 must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('roi_package_id', $exception->errors());
        }
        $this->assertSame(10, $member->investments()->count());
        $this->assertSame(100000, $wallets->get($member, $workspace)->balance());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($workspace));
    }
}
