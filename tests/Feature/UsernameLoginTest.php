<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UsernameLoginTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Notification::fake();
        Storage::fake('local');
    }

    public function test_email_and_username_login_work_for_admins_and_members_without_case_sensitivity(): void
    {
        $this->get('/login')->assertOk()->assertSee('Email atau username')->assertDontSee('type="email"', false);
        foreach (['super-admin' => 'admin_login', 'member' => 'member_login'] as $role => $username) {
            $user = User::factory()->create(['username' => $username, 'password' => 'MemberPass123']);
            $user->assignRole($role);
            foreach ([strtoupper($user->email), ' '.strtoupper($username).' '] as $identifier) {
                $this->post('/login', ['email' => $identifier, 'password' => 'MemberPass123'])
                    ->assertSessionHasNoErrors()->assertRedirect('/dashboard');
                $this->assertAuthenticatedAs($user);
                $this->post('/logout')->assertRedirect('/');
            }
        }
    }

    public function test_username_login_checks_password_and_excludes_inactive_or_archived_accounts(): void
    {
        $user = User::factory()->create(['username' => 'member_login', 'password' => 'MemberPass123']);
        $this->post('/login', ['email' => $user->username, 'password' => 'WrongPass123'])->assertSessionHasErrors('email');
        $user->forceFill(['active' => false])->save();
        $this->post('/login', ['email' => $user->username, 'password' => 'MemberPass123'])->assertSessionHasErrors('email');
        $user->forceFill(['active' => true])->save();
        $user->delete();
        $this->post('/login', ['email' => $user->username, 'password' => 'MemberPass123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_switching_between_email_and_username_does_not_bypass_the_attempt_limit(): void
    {
        $user = User::factory()->create(['username' => 'rate_limit', 'password' => 'MemberPass123']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $i % 2 ? $user->email : $user->username, 'password' => 'WrongPass123'])
                ->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => strtoupper($user->username), 'password' => 'MemberPass123'])
            ->assertSessionHasErrors(['email' => 'Terlalu banyak percobaan. Coba dalam 60 detik.']);
        $this->assertGuest();
    }

    public function test_members_can_choose_and_update_their_username_and_duplicates_are_rejected(): void
    {
        $data = ['name' => 'Member Baru', 'username' => 'Member_Baru', 'email' => 'member-baru@example.test',
            'password' => 'MemberPass123', 'password_confirmation' => 'MemberPass123'];
        $this->post('/register', $data)->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame('member_baru', $user->username);
        $this->get('/profile')->assertOk()->assertSee('member_baru');
        $this->patch('/profile', ['name' => $user->name, 'email' => $user->email, 'username' => 'Username_Baru'])
            ->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->post('/login', ['email' => 'USERNAME_BARU', 'password' => 'MemberPass123'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        $this->post('/register', array_replace($data, ['email' => 'another@example.test', 'username' => 'USERNAME_BARU']))
            ->assertSessionHasErrors('username');
        $this->travel(61)->seconds();
        foreach (['a', 'has space', 'name@example.test', str_repeat('a', 31)] as $invalid) {
            $this->post('/register', array_replace($data, ['username' => $invalid]))->assertSessionHasErrors('username');
        }
    }

    public function test_profile_cannot_take_another_username_and_old_clients_preserve_existing_username(): void
    {
        $owner = User::factory()->create(['username' => 'already_taken']);
        $user = User::factory()->create(['username' => 'original_name']);
        $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => $user->email, 'username' => strtoupper($owner->username)])
            ->assertSessionHasErrors('username');
        $this->assertSame('original_name', $user->refresh()->username);
        $this->patch('/profile', ['name' => $user->name, 'email' => $user->email])->assertSessionHasNoErrors();
        $this->assertSame('original_name', $user->refresh()->username);
    }

    public function test_account_seed_changes_the_legacy_admin_email_in_place_and_clears_old_access(): void
    {
        $admin = User::factory()->create(['email' => 'admin@copp.test', 'password' => 'OldPassword123']);
        $admin->assignRole('super-admin');
        $id = $admin->id;
        DB::table('sessions')->insert(['id' => 'legacy-admin', 'user_id' => $id, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('password_reset_tokens')->insert(['email' => $admin->email, 'token' => 'old-token', 'created_at' => now()]);
        $this->seed(AccountSeeder::class);
        $this->assertSame($id, User::where('email', 'admin@copp.production')->firstOrFail()->id);
        $this->assertSame('didisaidi', $admin->refresh()->username);
        $this->assertDatabaseMissing('users', ['email' => 'admin@copp.test']);
        $this->assertDatabaseMissing('sessions', ['user_id' => $id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'admin@copp.test']);
        $this->post('/login', ['email' => 'admin@copp.test', 'password' => 'admin123'])->assertSessionHasErrors('email');
        foreach (['Didisaidi', 'admin@copp.production'] as $identifier) {
            $this->post('/login', ['email' => $identifier, 'password' => 'admin123'])->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($admin);
            $this->post('/logout');
        }
        $this->seed(AccountSeeder::class);
        $this->assertSame(1, User::where('email', 'admin@copp.production')->count());
    }

    public function test_admin_can_assign_a_member_username_and_the_member_can_use_it_to_log_in(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin)->post('/members', [
            'name' => 'Member Internal', 'username' => 'Member_Internal', 'email' => 'internal@example.test',
            'password' => 'MemberPass123', 'password_confirmation' => 'MemberPass123',
        ])->assertSessionHasNoErrors()->assertRedirect('/members');
        $member = User::where('email', 'internal@example.test')->firstOrFail();
        $this->assertSame('member_internal', $member->username);
        $this->get('/members/'.$member->id.'/edit')->assertOk()->assertSee('member_internal');
        $this->put('/members/'.$member->id, [
            'name' => $member->name, 'email' => $member->email, 'username' => 'Username_Internal', 'active' => '1',
        ])->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->post('/login', ['email' => 'USERNAME_INTERNAL', 'password' => 'MemberPass123'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($member);
    }

    public function test_account_seed_refuses_to_merge_two_different_admin_accounts(): void
    {
        $old = User::factory()->create(['email' => 'admin@copp.test']);
        $old->assignRole('super-admin');
        $new = User::factory()->create(['email' => 'admin@copp.production']);
        $new->assignRole('super-admin');
        try {
            $this->seed(AccountSeeder::class);
            $this->fail('Two separate accounts must not be merged automatically.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('dua akun admin', $exception->getMessage());
        }
        $this->assertSame('admin@copp.test', $old->refresh()->email);
        $this->assertSame('admin@copp.production', $new->refresh()->email);
    }

    public function test_account_seed_refuses_to_overwrite_a_username_owned_by_another_member(): void
    {
        $other = User::factory()->create(['username' => 'didisaidi']);
        $other->assignRole('member');
        $admin = User::factory()->create(['email' => 'admin@copp.test']);
        $admin->assignRole('super-admin');
        try {
            $this->seed(AccountSeeder::class);
            $this->fail('An occupied username must stop the account reset.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Username Didisaidi', $exception->getMessage());
        }
        $this->assertSame('admin@copp.test', $admin->refresh()->email);
        $this->assertSame('didisaidi', $other->refresh()->username);
    }
}
