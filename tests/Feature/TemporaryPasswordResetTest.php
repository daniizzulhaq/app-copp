<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TemporaryPasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'Asia/Jakarta'));
        config(['nexavest.temporary_password_reset_enabled' => true, 'app.url' => 'http://localhost']);
        $this->user = User::factory()->unverified()->create(['password' => 'OriginalPass123']);
        $this->user->assignRole('member');
    }

    public function test_direct_flow_resets_without_mail_and_revokes_sessions_and_existing_reset_tokens(): void
    {
        $oldRemember = $this->user->remember_token;
        $this->get('/forgot-password')->assertOk()->assertSee('Lanjut buat password baru')->assertDontSee('Kirim tautan reset');
        $this->post('/forgot-password', ['email' => strtoupper($this->user->email)])
            ->assertSessionHasNoErrors()->assertRedirect('/reset-password/new');
        $this->get('/reset-password/new')->assertOk()->assertSee($this->user->email)->assertHeader('Cache-Control', 'no-store, private');
        DB::table('sessions')->insert([
            'id' => 'old-browser-session', 'user_id' => $this->user->id,
            'payload' => '', 'last_activity' => now()->timestamp,
        ]);
        Password::createToken($this->user);
        $this->post('/reset-password/new', ['password' => 'NewMemberPass123', 'password_confirmation' => 'NewMemberPass123'])
            ->assertSessionHasNoErrors()->assertSessionMissing('temporary_password_reset')->assertRedirect('/login');
        $this->assertTrue(Hash::check('NewMemberPass123', $this->user->refresh()->password));
        $this->assertFalse(Hash::check('OriginalPass123', $this->user->password));
        $this->assertNotSame($oldRemember, $this->user->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->user->email]);
        $this->assertGuest();
        Notification::assertNothingSent();
        $this->post('/login', ['email' => $this->user->email, 'password' => 'NewMemberPass123'])
            ->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_unknown_and_inactive_emails_cannot_open_the_reset_form(): void
    {
        $this->post('/forgot-password', ['email' => 'missing@example.test'])->assertSessionHasErrors('email')
            ->assertSessionMissing('temporary_password_reset');
        $this->user->forceFill(['active' => false])->save();
        $this->post('/forgot-password', ['email' => $this->user->email])->assertSessionHasErrors('email')
            ->assertSessionMissing('temporary_password_reset');
        $this->get('/reset-password/new')->assertRedirect('/forgot-password');
        Notification::assertNothingSent();
    }

    public function test_reset_requires_the_email_step_in_the_same_browser_session(): void
    {
        $this->get('/reset-password/new')->assertRedirect('/forgot-password');
        $this->post('/reset-password/new', [
            'email' => $this->user->email, 'password' => 'NewMemberPass123', 'password_confirmation' => 'NewMemberPass123',
        ])->assertRedirect('/forgot-password');
        $this->assertTrue(Hash::check('OriginalPass123', $this->user->refresh()->password));
    }

    public function test_submitted_account_fields_cannot_change_the_selected_account_and_reset_is_single_use(): void
    {
        $other = User::factory()->create(['password' => 'OtherOriginal123']);
        $this->post('/forgot-password', ['email' => $this->user->email])->assertRedirect('/reset-password/new');
        $this->post('/reset-password/new', [
            'email' => $other->email, 'user_id' => $other->id,
            'password' => 'NewMemberPass123', 'password_confirmation' => 'NewMemberPass123',
        ])->assertSessionHasNoErrors()->assertRedirect('/login');
        $this->assertTrue(Hash::check('NewMemberPass123', $this->user->refresh()->password));
        $this->assertTrue(Hash::check('OtherOriginal123', $other->refresh()->password));
        $this->post('/reset-password/new', ['password' => 'ReplayPass123', 'password_confirmation' => 'ReplayPass123'])
            ->assertRedirect('/forgot-password');
        $this->assertTrue(Hash::check('NewMemberPass123', $this->user->refresh()->password));
    }

    public function test_password_confirmation_is_required_and_a_corrected_submission_can_succeed(): void
    {
        $this->post('/forgot-password', ['email' => $this->user->email])->assertRedirect('/reset-password/new');
        $this->post('/reset-password/new', ['password' => 'NewMemberPass123', 'password_confirmation' => 'WrongConfirmation123'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('OriginalPass123', $this->user->refresh()->password));
        $this->post('/reset-password/new', ['password' => 'NewMemberPass123', 'password_confirmation' => 'NewMemberPass123'])
            ->assertSessionHasNoErrors()->assertRedirect('/login');
    }

    public function test_expired_session_cannot_reset_the_password(): void
    {
        $this->post('/forgot-password', ['email' => $this->user->email])->assertRedirect('/reset-password/new');
        $this->travelTo(now()->addMinutes(15));
        $this->post('/reset-password/new', ['password' => 'NewMemberPass123', 'password_confirmation' => 'NewMemberPass123'])
            ->assertRedirect('/forgot-password')->assertSessionMissing('temporary_password_reset');
        $this->assertTrue(Hash::check('OriginalPass123', $this->user->refresh()->password));
    }

    public function test_an_intervening_password_change_invalidates_the_reset_session(): void
    {
        $this->post('/forgot-password', ['email' => $this->user->email])->assertRedirect('/reset-password/new');
        $this->user->update(['password' => 'AlreadyChanged123']);
        $this->post('/reset-password/new', ['password' => 'NewMemberPass123', 'password_confirmation' => 'NewMemberPass123'])
            ->assertRedirect('/forgot-password');
        $this->assertTrue(Hash::check('AlreadyChanged123', $this->user->refresh()->password));
    }

    public static function serverContexts(): array
    {
        return [
            'public production server' => ['production', 'https://public.example', 'https://public.example', '198.51.100.10', null],
            'public staging server' => ['staging', 'https://staging.example', 'https://staging.example', '198.51.100.10', null],
            'public application URL' => ['testing', 'https://public.example', 'http://localhost', '127.0.0.1', null],
            'public request host' => ['testing', 'http://localhost', 'https://public.example', '127.0.0.1', null],
            'remote network' => ['testing', 'http://localhost', 'http://localhost', '198.51.100.10', null],
            'public user behind trusted proxy' => ['production', 'https://public.example', 'https://public.example', '127.0.0.1', '198.51.100.10'],
        ];
    }

    #[DataProvider('serverContexts')]
    public function test_direct_reset_works_on_servers_and_public_networks_without_sending_email(
        string $environment, string $appUrl, string $requestUrl, string $remoteAddress, ?string $forwardedFor,
    ): void {
        app()->instance('env', $environment);
        config(['app.url' => $appUrl]);
        $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress]);
        if ($forwardedFor) {
            $this->withHeader('X-Forwarded-For', $forwardedFor);
        }
        $this->get($requestUrl.'/forgot-password')->assertOk()->assertSee('Lanjut buat password baru')->assertDontSee('Kirim tautan reset');
        $response = $this->post($requestUrl.'/forgot-password', [
            '_token' => $this->app['session']->token(), 'email' => $this->user->email,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('/reset-password/new', parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->get($requestUrl.'/reset-password/new')->assertOk()->assertSee($this->user->email);
        $this->post($requestUrl.'/reset-password/new', [
            '_token' => $this->app['session']->token(),
            'password' => 'NewMemberPass123', 'password_confirmation' => 'NewMemberPass123',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(Hash::check('NewMemberPass123', $this->user->refresh()->password));
        Notification::assertNothingSent();
    }

    public function test_direct_reset_can_be_explicitly_disabled(): void
    {
        config(['nexavest.temporary_password_reset_enabled' => false]);
        $this->get('/forgot-password')->assertOk()->assertSee('Kirim tautan reset')->assertDontSee('Lanjut buat password baru');
        $this->get('/reset-password/new')->assertNotFound();
        $this->post('/reset-password/new', [
            'password' => 'NewMemberPass123', 'password_confirmation' => 'NewMemberPass123',
        ])->assertNotFound();
        $this->assertTrue(Hash::check('OriginalPass123', $this->user->refresh()->password));
    }

    public function test_email_token_reset_still_works_when_the_temporary_flow_is_disabled(): void
    {
        config(['nexavest.temporary_password_reset_enabled' => false]);
        $this->post('/forgot-password', ['email' => $this->user->email])->assertSessionHasNoErrors()->assertRedirect();
        Notification::assertSentTo($this->user, ResetPassword::class);
        $token = Notification::sent($this->user, ResetPassword::class)->first()->token;
        $this->post('/reset-password', [
            'token' => $token, 'email' => $this->user->email,
            'password' => 'NewMemberPass123', 'password_confirmation' => 'NewMemberPass123',
        ])->assertSessionHasNoErrors()->assertRedirect('/login');
        $this->assertTrue(Hash::check('NewMemberPass123', $this->user->refresh()->password));
    }
}
