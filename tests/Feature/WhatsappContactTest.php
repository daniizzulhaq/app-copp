<?php

namespace Tests\Feature;

use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WhatsappContactTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $member;
    private SimulationScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $this->scenario = SimulationScenario::factory()->create(['mode' => 'local']);
        SystemSetting::whereKey('support_whatsapp_number')->delete();
    }

    public function test_admin_can_save_whatsapp_and_member_link_uses_the_saved_number(): void
    {
        $this->actingAs($this->admin)->get('/settings')->assertOk()->assertSee('Nomor WhatsApp admin');
        foreach (['0812-3456-7890', '+62 812 3456 7890', '6281234567890'] as $number) {
            $this->actingAs($this->admin)->put('/settings', [
                'default_scenario' => $this->scenario->id, 'support_whatsapp_number' => $number,
            ])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertDatabaseHas('system_settings', ['key' => 'support_whatsapp_number', 'value' => '6281234567890']);
            $this->actingAs($this->member)->get('/notifications')->assertOk()->assertSee('https://wa.me/6281234567890?text=', false);
        }
        $this->actingAs($this->admin)->get('/settings')->assertSee('6281234567890');
    }

    public function test_clearing_the_number_hides_the_icon_and_omitting_the_field_preserves_it(): void
    {
        SystemSetting::updateOrCreate(['key' => 'support_whatsapp_number'], ['value' => '6281234567890']);
        $this->actingAs($this->admin)->put('/settings', ['default_scenario' => $this->scenario->id])->assertSessionHasNoErrors();
        $this->assertSame('6281234567890', SystemSetting::supportWhatsappNumber());
        $this->put('/settings', ['default_scenario' => $this->scenario->id, 'support_whatsapp_number' => ''])->assertSessionHasNoErrors();
        $this->assertSame('', SystemSetting::supportWhatsappNumber());
        $this->actingAs($this->member)->get('/notifications')->assertOk()->assertDontSee('class="member-whatsapp-button"', false);
    }

    public function test_only_admin_can_update_the_number_and_invalid_numbers_are_rejected(): void
    {
        $data = ['default_scenario' => $this->scenario->id, 'support_whatsapp_number' => '6281234567890'];
        $this->put('/settings', $data)->assertRedirect('/login');
        $this->actingAs($this->member)->put('/settings', $data)->assertForbidden();
        $this->assertSame('', SystemSetting::supportWhatsappNumber());
        $this->actingAs($this->admin);
        foreach (['123', 'https://wa.me/6281234567890', '62812abc567890', str_repeat('1', 16), ['6281234567890']] as $number) {
            $this->put('/settings', ['default_scenario' => $this->scenario->id, 'support_whatsapp_number' => $number])
                ->assertSessionHasErrors('support_whatsapp_number');
        }
        $this->assertSame('', SystemSetting::supportWhatsappNumber());
    }
}
