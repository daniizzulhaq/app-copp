<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Services\LedgerIntegrityService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductPricingTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;
    private User $member;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->workspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running', 'start_date' => today()]);
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->withSession(['scenario_id' => $this->workspace->id]);
    }

    public static function productPrices(): array
    {
        return ['fifty thousand' => [50000], 'fifty rupiah' => [50], 'one rupiah' => [1]];
    }

    private function productData(int $price): array
    {
        return [
            'name' => 'Produk harga admin', 'minimum_amount' => $price, 'daily_rate_bps' => 500,
            'duration_days' => 42, 'total_roi_bps' => 21000, 'purchase_limit' => 3, 'fixed_amount' => true, 'active' => true,
        ];
    }

    #[DataProvider('productPrices')]
    public function test_admin_can_create_a_product_below_one_hundred_thousand_and_member_can_buy_it(int $price): void
    {
        $this->actingAs($this->admin)->post('/packages', $this->productData($price))
            ->assertRedirect('/packages')->assertSessionHasNoErrors();
        $package = RoiPackage::where('name', 'Produk harga admin')->firstOrFail();
        $this->assertSame($price, $package->minimum_amount);
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, $price * 2, 'product-price-fund');

        $response = $this->actingAs($this->member)->get('/investments?package='.$package->id)->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $input = $xpath->query('//input[@name="amount"]')->item(0);
        $this->assertSame((string) $price, $input->getAttribute('min'));
        $this->assertSame((string) $price, $input->getAttribute('value'));
        $this->assertTrue($input->hasAttribute('readonly'));
        $selected = $xpath->query('//select[@name="roi_package_id"]/option[@selected]')->item(0);
        $this->assertSame((string) $package->id, $selected->getAttribute('value'));

        $payload = ['roi_package_id' => $package->id, 'amount' => $price, 'request_key' => (string) Str::uuid()];
        $this->post('/investments', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/investments', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $investment = Investment::where('user_id', $this->member->id)->sole();
        $this->assertSame($price, $investment->principal);
        $this->assertSame($price, app(WalletService::class)->get($this->member, $this->workspace)->balance());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_fixed_product_still_requires_its_actual_price(): void
    {
        $package = RoiPackage::factory()->create(['minimum_amount' => 50000, 'fixed_amount' => true]);
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, 200000, 'fixed-price-fund');
        $this->actingAs($this->member);
        foreach ([49999, 50001, 0, -1] as $amount) {
            $this->post('/investments', ['roi_package_id' => $package->id, 'amount' => $amount, 'request_key' => (string) Str::uuid()])
                ->assertSessionHasErrors('amount');
        }
        $this->assertSame(0, Investment::where('user_id', $this->member->id)->count());
        $this->assertSame(200000, app(WalletService::class)->get($this->member, $this->workspace)->balance());
    }

    public function test_variable_product_uses_its_own_minimum(): void
    {
        $package = RoiPackage::factory()->create(['minimum_amount' => 25000, 'fixed_amount' => false]);
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, 100000, 'variable-price-fund');
        $this->actingAs($this->member)->post('/investments', [
            'roi_package_id' => $package->id, 'amount' => 24999, 'request_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('amount');
        $this->post('/investments', [
            'roi_package_id' => $package->id, 'amount' => 35000, 'request_key' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(35000, Investment::where('user_id', $this->member->id)->sole()->principal);
        $this->assertSame(65000, app(WalletService::class)->get($this->member, $this->workspace)->balance());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_admin_can_lower_an_existing_product_price(): void
    {
        $package = RoiPackage::factory()->create(['minimum_amount' => 100000, 'fixed_amount' => true]);
        $this->actingAs($this->admin)->put('/packages/'.$package->id, $this->productData(50000))
            ->assertRedirect('/packages')->assertSessionHasNoErrors();
        $this->assertSame(50000, $package->refresh()->minimum_amount);
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, 50000, 'edited-price-fund');
        $this->actingAs($this->member)->post('/investments', [
            'roi_package_id' => $package->id, 'amount' => 50000, 'request_key' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(50000, Investment::where('user_id', $this->member->id)->sole()->principal);
        $this->assertSame(0, app(WalletService::class)->get($this->member, $this->workspace)->balance());
    }

    public function test_admin_cannot_create_a_product_with_a_nonpositive_price(): void
    {
        $this->actingAs($this->admin);
        foreach ([0, -1] as $price) {
            $this->post('/packages', $this->productData($price))->assertSessionHasErrors('minimum_amount');
        }
        $this->assertDatabaseMissing('roi_packages', ['name' => 'Produk harga admin']);
    }
}
