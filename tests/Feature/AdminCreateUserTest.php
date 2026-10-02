<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_user_with_given_password(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Sokha',
            'email' => 'sokha@example.com',
            'password' => 'chosen-pass-1',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Sokha')
            ->assertJsonPath('data.email', 'sokha@example.com')
            ->assertJsonPath('data.role', 'user')
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.storage_used_bytes', 0)
            ->assertJsonPath('data.storage_quota_bytes', (int) config('max-tune.default_storage_quota_bytes'))
            ->assertJsonMissingPath('temporary_password')
            ->assertJsonMissingPath('data.password');

        $this->assertStringNotContainsString('chosen-pass-1', $response->getContent());

        // Same resource shape as the admin list
        $listed = $this->getJson('/api/admin/users')->json('data');
        $this->assertSame(
            collect($listed)->firstWhere('email', 'sokha@example.com'),
            $response->json('data'),
        );
    }

    public function test_generated_password_is_returned_once_and_works(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Dara',
            'email' => 'dara@example.com',
        ])->assertCreated();

        $temporary = $response->json('temporary_password');

        $this->assertIsString($temporary);
        $this->assertGreaterThanOrEqual(12, strlen($temporary));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $temporary);

        // Not echoed anywhere except the one-time field, and not in the list
        $this->assertSame(1, substr_count($response->getContent(), $temporary));
        $this->assertStringNotContainsString($temporary, $this->getJson('/api/admin/users')->getContent());

        // Only a hash is stored
        $stored = User::query()->where('email', 'dara@example.com')->value('password');
        $this->assertNotSame($temporary, $stored);
        $this->assertTrue(password_verify($temporary, $stored));

        // Two generated passwords differ
        $second = $this->postJson('/api/admin/users', [
            'name' => 'Vanna',
            'email' => 'vanna@example.com',
        ])->assertCreated()->json('temporary_password');
        $this->assertNotSame($temporary, $second);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', [
            'email' => 'dara@example.com',
            'password' => $temporary,
        ])->assertOk()->assertJsonPath('user.email', 'dara@example.com');
    }

    public function test_blank_password_generates_one(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/users', [
            'name' => 'Blank',
            'email' => 'blank@example.com',
            'password' => '',
        ])->assertCreated()->assertJsonStructure(['temporary_password']);
    }

    public function test_quota_override_is_applied(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $quota = 2 * 1024 * 1024 * 1024;

        $this->postJson('/api/admin/users', [
            'name' => 'Quota',
            'email' => 'quota@example.com',
            'quota_bytes' => $quota,
        ])->assertCreated()
            ->assertJsonPath('data.storage_quota_bytes', $quota);

        $this->assertSame($quota, (int) User::query()->where('email', 'quota@example.com')->value('storage_quota_bytes'));
    }

    public function test_cannot_create_an_admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/users', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'is_admin' => true,
            'role' => 'admin',
        ])->assertCreated()
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('data.role', 'user');

        $this->assertFalse(User::query()->where('email', 'sneaky@example.com')->firstOrFail()->isAdmin());
    }

    public function test_works_in_every_app_mode(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        foreach (['personal', 'invite', 'public'] as $mode) {
            config(['max-tune.mode' => $mode]);

            $this->postJson('/api/admin/users', [
                'name' => ucfirst($mode),
                'email' => "{$mode}@example.com",
            ])->assertCreated();
        }
    }

    public function test_validation_errors_use_the_standard_422_shape(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/users', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);

        $this->postJson('/api/admin/users', [
            'name' => 'X',
            'email' => 'not-an-email',
            'password' => 'short',
            'quota_bytes' => -5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'quota_bytes'])
            ->assertJsonStructure(['message', 'errors']);

        $this->assertSame(1, User::query()->count());
    }

    public function test_duplicate_email_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/admin/users', [
            'name' => 'Dupe',
            'email' => 'taken@example.com',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'A user with this email already exists');

        $this->assertSame(1, User::query()->where('email', 'taken@example.com')->count());
    }

    public function test_non_admin_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/admin/users', [
            'name' => 'Nope',
            'email' => 'nope@example.com',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nope@example.com']);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->postJson('/api/admin/users', [
            'name' => 'Anon',
            'email' => 'anon@example.com',
        ])->assertUnauthorized();

        $this->assertDatabaseMissing('users', ['email' => 'anon@example.com']);
    }

    public function test_created_user_can_log_in_and_sees_an_empty_library(): void
    {
        $admin = User::factory()->admin()->create();
        Track::factory()->for($admin, 'owner')->create(['title' => 'Admin Secret']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/users', [
            'name' => 'Newbie',
            'email' => 'newbie@example.com',
            'password' => 'newbie-pass-1',
        ])->assertCreated();

        $this->app['auth']->forgetGuards();

        $token = $this->postJson('/api/auth/login', [
            'email' => 'newbie@example.com',
            'password' => 'newbie-pass-1',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'newbie@example.com')
            ->assertJsonPath('user.is_admin', false);

        $this->app['auth']->forgetGuards();

        $library = $this->withToken($token)->getJson('/api/tracks')->assertOk();
        $library->assertJsonCount(0, 'data');
        $this->assertStringNotContainsString('Admin Secret', $library->getContent());

        // The new user cannot reach admin routes either
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_new_user_cannot_see_the_admins_library(): void
    {
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->for($admin, 'owner')->create(['title' => 'Admin Secret']);

        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/admin/users', [
            'name' => 'Peek',
            'email' => 'peek@example.com',
        ])->assertCreated();

        Sanctum::actingAs(User::query()->findOrFail($created->json('data.id')));

        $this->getJson('/api/tracks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/tracks/{$track->id}")->assertForbidden();
    }

    public function test_password_is_never_logged_or_exposed_after_creation(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        Sanctum::actingAs(User::factory()->admin()->create());

        $given = $this->postJson('/api/admin/users', [
            'name' => 'Given',
            'email' => 'given@example.com',
            'password' => 'logged-pass-123',
        ])->assertCreated();

        $generated = $this->postJson('/api/admin/users', [
            'name' => 'Generated',
            'email' => 'generated@example.com',
        ])->assertCreated();

        $temporary = $generated->json('temporary_password');

        // Failed validation must not echo or log the password either
        $failed = $this->postJson('/api/admin/users', [
            'name' => 'Failed',
            'email' => 'nope',
            'password' => 'logged-pass-456',
        ])->assertUnprocessable();
        $this->assertStringNotContainsString('logged-pass-456', $failed->getContent());

        foreach ($logged as $line) {
            $this->assertStringNotContainsString('logged-pass-123', $line);
            $this->assertStringNotContainsString('logged-pass-456', $line);
            $this->assertStringNotContainsString($temporary, $line);
        }

        $this->assertStringNotContainsString('logged-pass-123', $given->getContent());

        // Neither the list nor /me nor the stored column expose a plain password
        $list = $this->getJson('/api/admin/users');
        $this->assertStringNotContainsString('logged-pass-123', $list->getContent());
        $this->assertStringNotContainsString($temporary, $list->getContent());

        $this->assertNotSame('logged-pass-123', User::query()->where('email', 'given@example.com')->value('password'));
        $this->assertNotSame($temporary, User::query()->where('email', 'generated@example.com')->value('password'));
        $this->assertStringContainsString('no-store', (string) $generated->headers->get('Cache-Control'));
    }
}
