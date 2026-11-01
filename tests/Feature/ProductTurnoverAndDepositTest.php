<?php

namespace Tests\Feature;

use App\Models\DailyProductTurnover;
use App\Models\MockPaymentInvoice;
use App\Models\Referral;
use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\LedgerIntegrityService;
use App\Services\ManualDepositService;
use App\Services\MemberBonusService;
use App\Services\ProductTurnoverService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductTurnoverAndDepositTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;

    private User $member;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-11-01 23:59:00', 'Asia/Jakarta'));
        $this->workspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running', 'start_date' => today()]);
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        SystemSetting::updateOrCreate(['key' => 'local_workspace'], ['value' => (string) $this->workspace->id]);
        SystemSetting::whereKey('deposit_unique_code_cursor')->update(['value' => '122']);
    }

    private function deposit(int $amount = 100000): MockPaymentInvoice
    {
        return app(ManualDepositService::class)->create($this->member, $this->workspace, $amount, (string) Str::uuid());
    }

    public function test_optional_proof_can_be_submitted_and_approved_without_duplicate_wallet_credit(): void
    {
        $invoice = $this->deposit();
        $this->assertSame(123, $invoice->unique_code);
        $this->assertSame(100123, $invoice->transfer_amount);
        $this->actingAs($this->member)->get('/payments/'.$invoice->id)
            ->assertOk()->assertSee('Rp100.123')->assertSee('Bukti transfer (opsional)');

        for ($n = 0; $n < 2; $n++) {
            $this->actingAs($this->member)->post('/payments/'.$invoice->id.'/submit', ['reference' => 'Pengirim member'])
                ->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->assertSame('awaiting_review', $invoice->refresh()->status);
        $this->assertNull($invoice->transfer_proof_path);
        $this->assertSame(123, $invoice->reserved_unique_code);
        $this->assertSame(0, app(WalletService::class)->get($this->member, $this->workspace)->balance());
        for ($n = 0; $n < 2; $n++) {
            $this->actingAs($this->admin)->patch('/payments/'.$invoice->id.'/review', ['decision' => 'approved'])
                ->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->assertSame(100000, app(WalletService::class)->get($this->member, $this->workspace)->balance());
        $this->assertNull($invoice->refresh()->reserved_unique_code);
        $this->assertSame(123, $invoice->unique_code);
        $this->assertSame(100123, $invoice->transfer_amount);
        $this->assertSame(0, DailyProductTurnover::where('simulation_scenario_id', $this->workspace->id)->count());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_attached_proof_is_validated_and_private(): void
    {
        $invoice = $this->deposit();
        $this->actingAs($this->member)->post('/payments/'.$invoice->id.'/submit', [
            'reference' => 'Pengirim member', 'transfer_proof' => UploadedFile::fake()->create('bad.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('transfer_proof');
        $this->assertSame('pending', $invoice->refresh()->status);
        $this->actingAs($this->member)->post('/payments/'.$invoice->id.'/submit', [
            'reference' => 'Pengirim member',
            'transfer_proof' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($invoice->refresh()->transfer_proof_hash);
        Storage::disk('local')->assertExists($invoice->transfer_proof_path);
        $this->get('/payments/'.$invoice->id.'/proof')->assertOk();
        $other = User::factory()->create();
        $other->assignRole('member');
        $this->actingAs($other)->get('/payments/'.$invoice->id.'/proof')->assertForbidden();
        $this->actingAs($other)->post('/payments/'.$invoice->id.'/submit', ['reference' => 'Other sender'])->assertForbidden();
    }

    public function test_codes_are_unique_across_workspaces_and_stable_on_retries_and_wraparound(): void
    {
        $first = $this->deposit();
        $service = app(ManualDepositService::class);
        $retry = $service->create($this->member, $this->workspace, 100000, $first->request_key);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame($first->transfer_amount, $retry->transfer_amount);
        SystemSetting::whereKey('deposit_unique_code_cursor')->update(['value' => '122']);
        $otherWorkspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running']);
        $second = $service->create($this->member, $otherWorkspace, 100000, (string) Str::uuid());
        $this->assertSame(124, $second->unique_code);
        SystemSetting::whereKey('deposit_unique_code_cursor')->update(['value' => '999']);
        $third = $this->deposit(100123);
        $this->assertSame(1, $third->unique_code);
        $this->assertSame(101001, $third->transfer_amount);
        $this->assertSame($third->unique_code, $third->transfer_amount % 1000);
        $this->assertGreaterThan($third->amount, $third->transfer_amount);
        $this->actingAs($this->member)->get('/payments/'.$third->id)->assertOk()->assertSee('001');
    }

    public function test_terminal_states_release_codes_but_review_queue_keeps_them_after_deadline(): void
    {
        $service = app(ManualDepositService::class);
        $cancelled = $this->deposit();
        $service->cancel($cancelled, $this->member);
        $this->assertNull($cancelled->refresh()->reserved_unique_code);
        SystemSetting::whereKey('deposit_unique_code_cursor')->update(['value' => '122']);
        $rejected = $this->deposit();
        $this->assertSame(123, $rejected->unique_code);
        $service->submit($rejected, $this->member, 'Rejected sender', null);
        $service->review($rejected, $this->admin, false, 'Transfer tidak ditemukan');
        $this->assertNull($rejected->refresh()->reserved_unique_code);
        $expired = $this->deposit();
        $review = $this->deposit();
        $service->submit($review, $this->member, 'Waiting sender', null);
        $this->travelTo(now()->addDays(2));
        $service->expire();
        $this->assertSame('expired', $expired->refresh()->status);
        $this->assertNull($expired->reserved_unique_code);
        $this->assertSame('awaiting_review', $review->refresh()->status);
        $this->assertNotNull($review->reserved_unique_code);
        $this->assertSame(0, app(WalletService::class)->get($this->member, $this->workspace)->balance());
    }

    public function test_database_rejects_a_second_active_reservation_for_the_same_code(): void
    {
        $first = $this->deposit();
        $second = $this->deposit();
        $this->expectException(QueryException::class);
        $second->update(['reserved_unique_code' => $first->unique_code]);
    }

    public function test_legacy_deposits_keep_their_original_transfer_amount_and_can_be_approved_without_proof(): void
    {
        $invoice = MockPaymentInvoice::create([
            'number' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(), 'user_id' => $this->member->id,
            'simulation_scenario_id' => $this->workspace->id, 'amount' => 100000, 'workflow' => 'manual_local',
            'method' => 'manual_local', 'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);
        $this->actingAs($this->member)->get('/payments/'.$invoice->id)
            ->assertOk()->assertSee('Tanpa kode (pengajuan lama)')->assertSee('Rp100.000');
        app(ManualDepositService::class)->submit($invoice, $this->member, 'Legacy sender', null);
        app(ManualDepositService::class)->review($invoice, $this->admin, true, null);
        $this->assertSame(100000, app(WalletService::class)->get($this->member, $this->workspace)->balance());
    }

    public function test_exhausted_codes_do_not_create_an_invoice(): void
    {
        $template = $this->deposit();
        $rows = [];
        foreach (range(1, 999) as $code) {
            if ($code === $template->unique_code) {
                continue;
            }
            $rows[] = [
                'number' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(), 'user_id' => $this->member->id,
                'simulation_scenario_id' => $this->workspace->id, 'amount' => 100000, 'workflow' => 'manual_local',
                'method' => 'manual_local', 'status' => 'pending', 'expires_at' => now()->addDay(),
                'unique_code' => $code, 'reserved_unique_code' => $code, 'transfer_amount' => 100000 + $code,
            ];
        }
        MockPaymentInvoice::insert($rows);
        $count = MockPaymentInvoice::count();
        try {
            $this->deposit();
            $this->fail('Exhausted unique codes must reject new deposits.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        $this->assertSame($count, MockPaymentInvoice::count());
    }

    public function test_turnover_counts_successful_purchases_once_per_wib_day_and_survives_product_edits(): void
    {
        app(WalletService::class)->creditDeposit($this->member, $this->workspace, 2000000, 'turnover-fund');
        $package = RoiPackage::factory()->create(['purchase_limit' => 10]);
        $service = app(InvestmentService::class);
        $first = $service->open($this->member, $this->workspace, $package, 100000, (string) Str::uuid());
        $service->open($this->member, $this->workspace, $package, 100000, $first->request_key);
        app(ProductTurnoverService::class)->record($first);
        $service->open($this->member, $this->workspace, $package, 200000, (string) Str::uuid());
        $day = DailyProductTurnover::where('simulation_scenario_id', $this->workspace->id)->firstOrFail();
        $this->assertSame(300000, $day->gross_amount);
        $this->assertSame(90000, $day->roi_amount);
        $this->assertSame(2, $day->purchase_count);
        $package->update(['minimum_amount' => 500000]);
        $first->update(['status' => 'completed']);
        try {
            $service->open($this->member, $this->workspace, $package, 100000, (string) Str::uuid());
            $this->fail('Invalid purchase must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(300000, $day->refresh()->gross_amount);
        }
        $this->travelTo(Carbon::parse('2026-11-02 00:01:00', 'Asia/Jakarta'));
        $service->open($this->member, $this->workspace, $package, 500000, (string) Str::uuid());
        $report = app(ProductTurnoverService::class)->report($this->workspace, '2026-11-01', '2026-11-02');
        $this->assertSame(800000, $report['gross']);
        $this->assertSame(240000, $report['roi']);
        $this->assertSame(150000, $report['today']->roi_amount);
        $this->assertSame(90000, $day->refresh()->roi_amount);
        $this->assertSame(2, $report['days']->total());
        $filtered = app(ProductTurnoverService::class)->report($this->workspace, '2026-11-01', '2026-11-01');
        $this->assertSame(90000, $filtered['roi']);
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
    }

    public function test_turnover_reports_are_admin_only_and_filter_dates(): void
    {
        $this->actingAs($this->member)->get('/operations')->assertForbidden();
        $this->actingAs($this->admin)->get('/operations?from=2026-11-01&to=2026-11-01')
            ->assertOk()->assertSee('Omzet masuk, alokasi ROI &amp; beban bonus', false)->assertSee('30% untuk ROI');
        $this->get('/dashboard?scenario='.$this->workspace->id)->assertOk()->assertSee('30% untuk ROI');
        $this->get('/operations?from=2026-11-02&to=2026-11-01')->assertSessionHasErrors('to');
        $this->actingAs($this->member)->get('/dashboard')->assertOk()->assertDontSee('30% untuk ROI');
    }

    public function test_report_separates_deposits_product_turnover_and_actual_bonus_costs_by_day(): void
    {
        $referrer = User::factory()->create();
        $referrer->assignRole('member');
        foreach ([$this->member, User::factory()->create(), User::factory()->create()] as $child) {
            Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $child->id, 'points' => 0]);
        }
        app(MemberBonusService::class)->missions($referrer, $this->workspace);
        $deposits = app(ManualDepositService::class);
        $first = $this->deposit();
        $deposits->submit($first, $this->member, 'First sender', null);
        $deposits->review($first, $this->admin, true, null);
        $deposits->review($first, $this->admin, true, null);
        $this->deposit(200000); // Pending requests must not become incoming funds or bonus costs.
        $rejected = $this->deposit(200000);
        $deposits->submit($rejected, $this->member, 'Rejected sender', null);
        $deposits->review($rejected, $this->admin, false, 'Transfer tidak ditemukan');

        $otherWorkspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running']);
        app(WalletService::class)->creditDeposit($referrer, $otherWorkspace, 500000, 'other-report-fund');
        app(MemberBonusService::class)->missions($referrer, $otherWorkspace);
        $report = app(ProductTurnoverService::class)->report($this->workspace, '2026-11-01', '2026-11-01');
        $this->assertSame(100000, $report['funds_in']);
        $this->assertSame(0, $report['gross']);
        $this->assertSame(0, $report['roi']);
        $this->assertSame(15000, $report['referral_bonus']);
        $this->assertSame(7000, $report['mission_bonus']);
        $this->assertSame(22000, $report['bonus']);
        $this->assertSame(22000, $report['days']->first()->bonus_amount);
        $this->assertSame(1, $report['days']->total());

        $package = RoiPackage::factory()->create();
        app(InvestmentService::class)->open($this->member, $this->workspace, $package, 100000, (string) Str::uuid());
        $this->travelTo(Carbon::parse('2026-11-02 00:01:00', 'Asia/Jakarta'));
        $second = $this->deposit(200000);
        $deposits->submit($second, $this->member, 'Second sender', null);
        $deposits->review($second, $this->admin, true, null);

        $service = app(ProductTurnoverService::class);
        $report = $service->report($this->workspace, '2026-11-01', '2026-11-02');
        $this->assertSame(300000, $report['funds_in']);
        $this->assertSame(100000, $report['gross']);
        $this->assertSame(30000, $report['roi']);
        $this->assertSame(45000, $report['referral_bonus']);
        $this->assertSame(7000, $report['mission_bonus']);
        $this->assertSame(52000, $report['bonus']);
        $this->assertSame(2, $report['days']->total());
        $this->assertSame(200000, $report['today']->funds_in);
        $this->assertSame(30000, $report['today']->bonus_amount);
        $this->assertSame(0, $report['today']->gross_amount);
        $this->assertSame(0, $report['today']->roi_amount);
        $filtered = $service->report($this->workspace, '2026-11-01', '2026-11-01');
        $this->assertSame(100000, $filtered['funds_in']);
        $this->assertSame(22000, $filtered['bonus']);
        $this->assertSame(200000, $filtered['today']->funds_in);
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
        $this->actingAs($this->admin)->get('/operations?from=2026-11-01&to=2026-11-02')
            ->assertOk()->assertSee('Rp52.000')->assertSee('Rp45.000')->assertSee('Rp7.000')
            ->assertSee('Beban bonus')->assertSee('Dana masuk (deposit terverifikasi)');
        $this->get('/dashboard?scenario='.$this->workspace->id.'&from=2026-11-01&to=2026-11-02')
            ->assertOk()->assertSee('Rp52.000')->assertSee('Beban bonus');
    }

    public function test_bonus_only_dates_are_included_without_fabricating_product_turnover(): void
    {
        foreach (User::factory()->count(3)->create() as $child) {
            Referral::create(['referrer_id' => $this->member->id, 'referred_id' => $child->id, 'points' => 0]);
        }
        app(MemberBonusService::class)->missions($this->member, $this->workspace);
        $report = app(ProductTurnoverService::class)->report($this->workspace, '2026-11-01', '2026-11-01');
        $this->assertSame(5000, $report['bonus']);
        $this->assertSame(0, $report['funds_in']);
        $this->assertSame(0, $report['gross']);
        $this->assertSame(0, $report['roi']);
        $this->assertSame(1, $report['days']->total());
        $this->assertSame('2026-11-01', $report['days']->first()->period_date->toDateString());
        $this->assertSame(5000, $report['days']->first()->mission_bonus);
    }

    public function test_deposit_only_history_paginates_while_totals_cover_the_whole_filtered_period(): void
    {
        for ($day = 0; $day < 16; $day++) {
            $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'Asia/Jakarta')->addDays($day));
            app(WalletService::class)->creditDeposit($this->member, $this->workspace, 10000, 'history-fund:'.$day);
        }
        $this->actingAs($this->admin)->get('/operations?from=2026-11-01&to=2026-11-16&turnover_page=2')
            ->assertOk()->assertViewHas('turnover', fn ($report) => $report['days']->currentPage() === 2
                && $report['days']->count() === 1 && $report['days']->total() === 16
                && $report['funds_in'] === 160000 && $report['gross'] === 0 && $report['bonus'] === 0
                && $report['days']->first()->period_date->toDateString() === '2026-11-01');
    }
}
