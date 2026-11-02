<?php

namespace Tests\Feature;

use App\Models\MockPaymentInvoice;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\LedgerIntegrityService;
use App\Services\WalletService;
use App\Support\LandingContent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepositMethodsAndLandingContentTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $member;

    private SimulationScenario $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $this->workspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running', 'start_date' => today()]);
        SystemSetting::updateOrCreate(['key' => 'local_workspace'], ['value' => (string) $this->workspace->id]);
        SystemSetting::where('key', 'like', 'deposit_qris_%')->delete();
        SystemSetting::whereKey('landing_content')->delete();
    }

    private function configureQris(): void
    {
        $this->actingAs($this->admin)->put('/settings/deposit-methods', [
            'qris_dana_enabled' => '1', 'qris_dana_holder' => 'Merchant DANA',
            'qris_dana_image' => UploadedFile::fake()->image('dana.png', 300, 300),
            'qris_gopay_enabled' => '1', 'qris_gopay_holder' => 'Merchant GoPay',
            'qris_gopay_image' => UploadedFile::fake()->image('gopay.png', 300, 300),
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_only_admin_can_manage_qris_and_landing_content(): void
    {
        foreach (['/settings/deposit-methods', '/settings/landing-content'] as $url) {
            $this->get($url)->assertRedirect('/login');
            $this->put($url)->assertRedirect('/login');
        }
        $this->actingAs($this->member);
        foreach (['/settings/deposit-methods', '/settings/landing-content'] as $url) {
            $this->get($url)->assertForbidden();
            $this->put($url, [])->assertForbidden();
        }
        $this->actingAs($this->admin)->get('/settings/deposit-methods')->assertOk()->assertSee('QRIS DANA')->assertSee('QRIS GoPay');
        $this->get('/settings/landing-content')->assertOk()->assertSee('Edit artikel landing page');
        $this->get('/settings')->assertOk()->assertSee('Metode deposit QRIS')->assertSee('Edit artikel landing page');
    }

    public function test_qris_requires_a_valid_image_and_holder_before_activation(): void
    {
        $this->actingAs($this->admin)->put('/settings/deposit-methods', ['qris_dana_enabled' => '1'])
            ->assertSessionHasErrors('qris_dana_image');
        $this->put('/settings/deposit-methods', [
            'qris_dana_enabled' => '1', 'qris_dana_holder' => 'Merchant',
            'qris_dana_image' => UploadedFile::fake()->create('invalid.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('qris_dana_image');
        $this->put('/settings/deposit-methods', [
            'qris_dana_image' => UploadedFile::fake()->image('large.png')->size(2049),
        ])->assertSessionHasErrors('qris_dana_image');
        $this->assertFalse(SystemSetting::qrisMethods()['qris_dana']['enabled']);
        $this->assertCount(0, Storage::disk('local')->allFiles('deposit-qris'));
    }

    public function test_both_qris_methods_can_be_uploaded_selected_paid_and_verified_once(): void
    {
        $this->configureQris();
        $this->get('/settings/deposit-methods')->assertOk()->assertSee('Merchant DANA')->assertSee('Merchant GoPay');
        $this->get('/settings/deposit-methods/qris_dana/preview')->assertOk();
        $this->actingAs($this->member)->get('/payments')->assertOk()->assertSee('QRIS DANA')->assertSee('QRIS GoPay');

        foreach (SystemSetting::QRIS_METHODS as $method => $label) {
            $this->actingAs($this->member)->post('/payments', [
                'amount' => 100000, 'method' => $method, 'request_key' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors()->assertRedirect();
            $invoice = MockPaymentInvoice::where('user_id', $this->member->id)->latest('id')->firstOrFail();
            $this->assertSame($method, $invoice->method);
            $this->assertSame('manual_local', $invoice->workflow);
            Storage::disk('local')->assertExists($invoice->payment_destination['image']);
            $this->get('/payments/'.$invoice->id)->assertOk()->assertSee($label)
                ->assertSee($invoice->payment_destination['holder'])->assertSee(route('payments.qris', $invoice), false);
            $this->get('/payments/'.$invoice->id.'/qris')->assertOk();
            $this->post('/payments/'.$invoice->id.'/simulate', ['result' => 'paid_simulated', 'event_id' => (string) Str::uuid()])->assertForbidden();
            $this->post('/payments/'.$invoice->id.'/submit', ['reference' => 'Pembayar QRIS'])->assertSessionHasNoErrors();
            $this->actingAs($this->admin)->get('/payments/'.$invoice->id)->assertOk()->assertSee($label)->assertSee('Setujui deposit');
            for ($i = 0; $i < 2; $i++) {
                $this->patch('/payments/'.$invoice->id.'/review', ['decision' => 'approved'])->assertSessionHasNoErrors();
            }
            $this->assertSame('paid_local', $invoice->refresh()->status);
        }
        $this->assertSame(200000, app(WalletService::class)->get($this->member, $this->workspace)->balance());
        $this->assertSame([], app(LedgerIntegrityService::class)->check($this->workspace));
        $this->get('/payments')->assertOk()->assertSee('QRIS DANA')->assertSee('QRIS GoPay');
    }

    public function test_disabling_or_replacing_qris_preserves_existing_invoice_and_retry(): void
    {
        $this->configureQris();
        $data = ['amount' => 100000, 'method' => 'qris_dana', 'request_key' => (string) Str::uuid()];
        $this->actingAs($this->member)->post('/payments', $data)->assertSessionHasNoErrors();
        $invoice = MockPaymentInvoice::where('request_key', $data['request_key'])->firstOrFail();
        $oldDestination = $invoice->payment_destination;
        $this->actingAs($this->admin)->put('/settings/deposit-methods', [
            'qris_dana_holder' => 'New merchant', 'qris_dana_image' => UploadedFile::fake()->image('new.png'),
        ])->assertSessionHasNoErrors();
        $this->assertNotSame($oldDestination['image'], SystemSetting::qrisMethods()['qris_dana']['image']);
        $this->actingAs($this->member)->get('/payments')->assertOk()->assertDontSee('value="qris_dana"', false);
        $this->get('/payments/'.$invoice->id)->assertOk()->assertSee('Merchant DANA')->assertDontSee('New merchant');
        $this->get('/payments/'.$invoice->id.'/qris')->assertOk();
        $this->post('/payments', $data)->assertSessionHasNoErrors()->assertRedirect('/payments/'.$invoice->id);
        $this->assertSame($oldDestination, $invoice->refresh()->payment_destination);
        $this->post('/payments', array_replace($data, ['method' => 'qris_gopay']))->assertSessionHasErrors('request_key');
        $this->post('/payments', array_replace($data, ['request_key' => (string) Str::uuid()]))->assertSessionHasErrors('method');
        $other = User::factory()->create();
        $other->assignRole('member');
        $this->actingAs($other)->get('/payments/'.$invoice->id.'/qris')->assertForbidden();
        $this->get('/settings/deposit-methods/qris_dana/preview')->assertForbidden();
    }

    public function test_unavailable_or_unknown_methods_cannot_create_invoices(): void
    {
        $this->actingAs($this->member);
        foreach (['qris_dana', 'qris_gopay', 'qris_other', 'qris_dummy'] as $method) {
            $this->post('/payments', ['amount' => 100000, 'method' => $method, 'request_key' => (string) Str::uuid()])
                ->assertSessionHasErrors('method');
        }
        $this->assertSame(0, MockPaymentInvoice::where('user_id', $this->member->id)->count());
    }

    public function test_landing_defaults_and_admin_edits_are_public_and_html_is_escaped(): void
    {
        $this->get('/')->assertOk()->assertSee('Master plan COPP')->assertSee('Apakah saya harus menyetor uang?');
        $content = LandingContent::get();
        $content['utama']['judul'] = 'Judul baru dari admin';
        $content['fitur']['isi_1'] = "Artikel pertama\nBaris berikutnya";
        $content['faq']['jawaban_1'] = '<script>alert("test")</script>';
        $content['master_plan']['isi_1'] = 'Misi yang diperbarui';
        $this->actingAs($this->admin)->put('/settings/landing-content', ['content' => $content])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->get('/settings/landing-content')->assertOk()->assertSee('Judul baru dari admin')->assertSee('Misi yang diperbarui');
        auth()->logout();
        $this->get('/')->assertOk()->assertSee('Judul baru dari admin')->assertSee("Artikel pertama\nBaris berikutnya")
            ->assertSee('Misi yang diperbarui')->assertSee('<script>alert("test")</script>')
            ->assertDontSee('<script>alert("test")</script>', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'landing-content.updated', 'actor_id' => $this->admin->id]);
    }

    public function test_invalid_landing_edits_do_not_overwrite_saved_content(): void
    {
        $content = LandingContent::get();
        $content['utama']['judul'] = str_repeat('x', 251);
        $this->actingAs($this->admin)->put('/settings/landing-content', ['content' => $content])
            ->assertSessionHasErrors('content.utama.judul');
        $content = LandingContent::get();
        unset($content['faq']);
        $this->put('/settings/landing-content', ['content' => $content])->assertSessionHasErrors('content.faq');
        $this->assertDatabaseMissing('system_settings', ['key' => 'landing_content']);
    }
}
