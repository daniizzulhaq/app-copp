<?php

namespace Tests\Feature;

use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\SimulationNotification;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class SimpleAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    private SimulationScenario $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'Asia/Jakarta'));
        $this->workspace = SimulationScenario::factory()->create(['mode' => 'local', 'status' => 'running', 'start_date' => today()]);
        SystemSetting::updateOrCreate(['key' => 'local_workspace'], ['value' => (string) $this->workspace->id]);
    }

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Member Baru', 'email' => 'new-member@example.test',
            'password' => 'MemberPass123', 'password_confirmation' => 'MemberPass123',
        ], $overrides);
    }

    public function test_registration_without_referral_logs_in_and_can_immediately_use_member_features(): void
    {
        $this->post('/register', $this->registration())->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $user = User::where('email', 'new-member@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->active);
        $this->assertTrue($user->hasRole('member'));
        $this->assertTrue(Hash::check('MemberPass123', $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseMissing('referrals', ['referred_id' => $user->id]);
        Notification::assertSentTo($user, SimulationNotification::class);
        Notification::assertNotSentTo($user, VerifyEmail::class);
        foreach (['/dashboard', '/wallet', '/payments', '/investments', '/withdrawals', '/referrals'] as $page) {
            $this->get($page)->assertOk();
        }
        $this->post('/payments', ['amount' => 100000, 'method' => 'manual_local', 'request_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('mock_payment_invoices', ['user_id' => $user->id, 'amount' => 100000, 'status' => 'pending']);
    }

    public function test_registration_accepts_an_optional_referral_code_and_prefills_referral_links(): void
    {
        $referrer = User::factory()->create();
        $referrer->assignRole('member');
        $this->get('/register?ref='.$referrer->referral_code)->assertOk()->assertSee($referrer->referral_code);
        $this->post('/register', $this->registration(['referral' => $referrer->referral_code]))
            ->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $user = User::where('email', 'new-member@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('referrals', ['referrer_id' => $referrer->id, 'referred_id' => $user->id]);
        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_invalid_referral_does_not_create_an_account_or_log_in(): void
    {
        $this->post('/register', $this->registration(['referral' => 'INVALID00000']))->assertSessionHasErrors('referral');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'new-member@example.test']);
        Notification::assertNothingSent();
    }

    public function test_registration_still_validates_password_confirmation_and_duplicate_email(): void
    {
        $this->post('/register', $this->registration(['password_confirmation' => 'DifferentPass123']))
            ->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'new-member@example.test']);
        $existing = User::factory()->create(['email' => 'existing@example.test']);
        $this->post('/register', $this->registration(['email' => $existing->email]))->assertSessionHasErrors('email');
        $this->assertGuest();
        Notification::assertNothingSent();
    }

    public function test_members_and_admins_with_legacy_2fa_can_log_in_with_only_email_and_password(): void
    {
        foreach (['member', 'super-admin'] as $role) {
            $user = User::factory()->unverified()->create(['password' => 'MemberPass123']);
            $user->assignRole($role);
            $user->forceFill([
                'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
                'two_factor_confirmed_at' => now(), 'two_factor_last_counter' => 123456,
            ])->save();
            $this->post('/login', ['email' => $user->email, 'password' => 'MemberPass123'])
                ->assertSessionHasNoErrors()->assertRedirect('/dashboard');
            $this->assertAuthenticatedAs($user);
            $this->get('/dashboard')->assertOk();
            $this->get('/profile')->assertOk()->assertDontSee('Two-factor authentication')->assertDontSee('Kode autentikator');
            $this->post('/logout')->assertRedirect('/');
            $this->assertGuest();
        }
    }

    public function test_wrong_passwords_and_inactive_accounts_still_cannot_log_in(): void
    {
        $user = User::factory()->create(['password' => 'MemberPass123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'WrongPassword123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $user->forceFill(['active' => false])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'MemberPass123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_attempt_limit_remains_enforced_without_2fa(): void
    {
        $user = User::factory()->create(['password' => 'MemberPass123']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'WrongPassword123'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'MemberPass123'])
            ->assertSessionHasErrors(['email' => 'Terlalu banyak percobaan. Coba dalam 60 detik.']);
        $this->assertGuest();
    }

    public function test_existing_unverified_accounts_can_change_email_without_verification_or_losing_access(): void
    {
        $user = User::factory()->unverified()->create();
        $user->assignRole('member');
        $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => 'updated-member@example.test'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('updated-member@example.test', $user->refresh()->email);
        $this->get('/dashboard')->assertOk();
        $this->get('/wallet')->assertOk();
        $this->get('/operations')->assertForbidden();
        Notification::assertNothingSent();
    }

    public function test_removed_2fa_and_email_verification_routes_are_unavailable(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Kode autentikator')->assertDontSee('one-time-code');
        $this->get('/register')->assertOk()->assertDontSee('simulation_consent');
        $user = User::factory()->unverified()->create();
        $user->assignRole('super-admin');
        $this->actingAs($user);
        $this->post('/profile/2fa/setup')->assertNotFound();
        $this->post('/profile/2fa/confirm')->assertNotFound();
        $this->delete('/profile/2fa')->assertNotFound();
        $this->get('/verify-email')->assertNotFound();
        $this->get('/verify-email/'.$user->id.'/'.sha1($user->email))->assertNotFound();
        $this->post('/email/verification-notification')->assertNotFound();
        Notification::assertNothingSent();
    }
}
