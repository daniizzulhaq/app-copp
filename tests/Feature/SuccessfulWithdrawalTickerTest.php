<?php

namespace Tests\Feature;

use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\InvestmentService;
use App\Services\WalletService;
use App\Services\WithdrawalSimulationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuccessfulWithdrawalTickerTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->workspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running', 'start_date' => today()]);
        SystemSetting::updateOrCreate(['key' => 'local_workspace'], ['value' => (string) $this->workspace->id]);
        $this->viewer = $this->member('Viewer');
        $this->actingAs($this->viewer);
    }

    private function member(string $name, array $attributes = []): User
    {
        $member = User::factory()->create(array_merge(['name' => $name], $attributes));
        $member->assignRole('member');

        return $member;
    }

    private function withdrawal(User $member, array $attributes = []): WithdrawalRequest
    {
        return WithdrawalRequest::create(array_merge([
            'user_id' => $member->id, 'simulation_scenario_id' => $this->workspace->id,
            'amount' => 100000, 'admin_fee' => 10000, 'method' => 'bank_transfer',
            'status' => 'approved', 'reviewed_at' => now(), 'request_key' => (string) Str::uuid(),
            'bank_name' => 'Private Bank', 'bank_account_number' => '9876543210123',
            'bank_account_holder' => $member->name, 'note' => 'Private withdrawal note',
        ], $attributes));
    }

    public function test_ticker_appears_after_admin_completes_a_real_withdrawal(): void
    {
        $member = $this->member('Budi Santoso', [
            'bank_name' => 'Private Bank', 'bank_account_number' => '9876543210123', 'bank_account_holder' => 'Budi Santoso',
        ]);
        SystemSetting::updateOrCreate(['key' => 'minimum_withdrawal_amount'], ['value' => '50000']);
        app(WalletService::class)->creditDeposit($member, $this->workspace, 300000, 'ticker-fund:'.$member->id);
        app(InvestmentService::class)->open($member, $this->workspace, RoiPackage::factory()->create(), 100000, (string) Str::uuid());
        $withdrawal = app(WithdrawalSimulationService::class)->request($member, $this->workspace, 100000, 'bank_transfer', null, (string) Str::uuid());
        $this->get('/dashboard')->assertOk()->assertDontSee('WD SUKSES');

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin)->patch('/withdrawals/'.$withdrawal->id, ['decision' => 'approved'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->viewer);
        foreach (['/dashboard', '/wallet', '/withdrawals', '/profile'] as $page) {
            $this->get($page)->assertOk()->assertSee('WD SUKSES')->assertSee('Bu***')
                ->assertSee('Rp100.000')->assertSee('berhasil WD')
                ->assertDontSee('Budi Santoso')->assertDontSee($member->email)
                ->assertDontSee('9876543210123')->assertDontSee('Private Bank');
        }
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertDontSee('WD SUKSES');
    }

    public function test_pending_rejected_and_unreviewed_requests_never_appear(): void
    {
        $this->withdrawal($this->member('Pending'), ['status' => 'pending', 'reviewed_at' => null]);
        $this->withdrawal($this->member('Rejected'), ['status' => 'rejected']);
        $this->withdrawal($this->member('Unreviewed'), ['reviewed_at' => null]);
        $this->get('/dashboard')->assertOk()->assertDontSee('WD SUKSES');
    }

    public function test_ticker_excludes_own_withdrawals_other_workspaces_and_nonmembers(): void
    {
        $this->withdrawal($this->viewer);
        $otherWorkspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running']);
        $this->withdrawal($this->member('Other workspace'), ['simulation_scenario_id' => $otherWorkspace->id]);
        $this->withdrawal($this->member('Cohort', ['is_cohort' => true]));
        $this->withdrawal($this->member('Inactive', ['active' => false]));
        $deleted = $this->member('Archived');
        $deleted->delete();
        $this->withdrawal($deleted);
        $staff = $this->member('Staff');
        $staff->assignRole('super-admin');
        $this->withdrawal($staff);
        $this->withdrawal(User::factory()->create());

        $this->get('/dashboard')->assertOk()->assertDontSee('WD SUKSES');
    }

    public function test_feed_is_limited_to_ten_latest_completions_and_escapes_names(): void
    {
        $member = $this->member('Siti Rahma');
        for ($i = 0; $i < 11; $i++) {
            $this->withdrawal($member, ['amount' => 100000 + $i, 'reviewed_at' => now()->subMinutes($i)]);
        }
        $feed = $this->get('/dashboard')->assertOk()->assertSee('Si***')->assertDontSee('Siti Rahma');
        $feed->assertSeeInOrder(['Rp100.000', 'Rp100.001', 'Rp100.009'])->assertDontSee('Rp100.010');

        $this->withdrawal($this->member('<script>alert(1)</script>'), ['reviewed_at' => now()->addSecond()]);
        $this->get('/dashboard')->assertOk()->assertSee('&lt;s***', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Private withdrawal note');
    }

    public function test_simulation_and_archived_workspaces_do_not_publish_success_notices(): void
    {
        $this->withdrawal($this->member('Simulation'));
        $this->workspace->update(['mode' => 'simulation']);
        $this->assertSame('', trim(view('member.withdrawal-ticker')->render()));
        $this->workspace->update(['mode' => 'local', 'status' => 'archived']);
        $this->assertSame('', trim(view('member.withdrawal-ticker')->render()));
    }
}
