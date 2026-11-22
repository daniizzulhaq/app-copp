<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\WalletService;
use Database\Seeders\ProductPlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberRoiDiagnosticsTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(now()->startOfDay()->addHours(10));
        $this->workspace = SimulationScenario::factory()->create([
            'mode' => 'local', 'plan_version' => 'turnover_v1', 'status' => 'running', 'start_date' => today(),
        ]);
        SystemSetting::updateOrCreate(['key' => 'local_workspace'], ['value' => (string) $this->workspace->id]);
        $this->seed(ProductPlanSeeder::class);
    }

    private function buy(): Investment
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        app(WalletService::class)->creditDeposit($member, $this->workspace, 100000, 'roi-diagnostic:'.$member->id);

        return app(InvestmentService::class)->open($member, $this->workspace,
            RoiPackage::where('active', true)->firstOrFail(), 100000, (string) Str::uuid());
    }

    public function test_purchase_followed_by_a_day_without_sales_has_no_claim_and_diagnostic_does_not_create_one(): void
    {
        $investment = $this->buy();
        $this->travelTo(now()->addDay());
        $transactions = $this->workspace->transactions()->count();
        $this->artisan('copp:roi-check', ['member' => $investment->user->email])
            ->expectsOutputToContain('Tidak ada pool ROI hari ini.')
            ->assertSuccessful();
        $this->assertSame(0, $investment->obligations()->count());
        $this->assertSame($transactions, $this->workspace->transactions()->count());
        $this->actingAs($investment->user)->get('/dashboard')->assertOk()
            ->assertSee('Belum ada ROI untuk diklaim')
            ->assertSee('disabled>Klaim ROI hari ini</button>', false);
    }

    public function test_diagnostic_detects_turnover_products_in_a_legacy_workspace(): void
    {
        $investment = $this->buy();
        $this->workspace->update(['plan_version' => 'legacy']);
        $this->travelTo(now()->addDay());
        $this->artisan('copp:roi-check', ['member' => $investment->user->email])
            ->expectsOutputToContain('Plan workspace tidak cocok: produk turnover berada pada workspace legacy.')
            ->assertSuccessful();
        $this->assertSame(0, $investment->obligations()->count());
    }

    public function test_diagnostic_does_not_expire_a_previous_days_claim(): void
    {
        $investment = $this->buy();
        $this->travelTo(now()->addDay());
        $this->buy();
        $claim = $investment->obligations()->firstOrFail();
        $this->travelTo(now()->addDay());
        $transactions = $this->workspace->transactions()->count();
        $this->artisan('copp:roi-check', ['member' => $investment->user->email])->assertSuccessful();
        $this->assertSame('due', $claim->fresh()->status);
        $this->assertSame(0, $claim->fresh()->expired_amount);
        $this->assertSame($transactions, $this->workspace->transactions()->count());
    }

    public function test_next_day_sales_make_a_twenty_five_thousand_claim_visible_without_running_a_scheduler(): void
    {
        $investment = $this->buy();
        $this->travelTo(now()->addDay());
        $this->buy();
        $claim = $investment->obligations()->whereDate('due_date', today())->firstOrFail();
        $this->assertSame(25000, $claim->remaining());
        $this->actingAs($investment->user)->get('/dashboard')->assertOk()
            ->assertSee('Rp25.000')->assertSee(route('roi.claim', $claim), false)
            ->assertSee('>Klaim ROI hari ini</button>', false)
            ->assertSeeInOrder(['Klaim ROI harian', 'Akses cepat transaksi', 'Member yang sudah WD'])
            ->assertSee('data-daily-roi-date="'.today()->toDateString().'"', false)
            ->assertSee('data-daily-roi-timezone="Asia/Jakarta"', false);
    }

    public function test_unknown_account_is_reported_without_falling_back_to_another_member(): void
    {
        $this->artisan('copp:roi-check', ['member' => 'missing-roi-account@example.invalid'])
            ->expectsOutput('Akun tidak ditemukan.')->assertFailed();
    }
}
