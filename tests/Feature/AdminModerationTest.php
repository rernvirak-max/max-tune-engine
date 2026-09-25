<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_disable_and_enable_users(): void
    {
        $admin = User::factory()->admin()->create();
        $friend = User::factory()->create(['email' => 'friend@example.com']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonFragment(['email' => 'friend@example.com']);

        $this->postJson("/api/admin/users/{$friend->id}/disable")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->assertSame(0, $friend->tokens()->count());

        // Disabled user cannot login
        $this->postJson('/api/auth/login', [
            'email' => 'friend@example.com',
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This account is disabled');

        $this->postJson("/api/admin/users/{$friend->id}/enable")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_admin_can_override_quota(): void
    {
        $admin = User::factory()->admin()->create();
        $friend = User::factory()->create();

        Sanctum::actingAs($admin);

        $quota = 2 * 1024 * 1024 * 1024;
        $this->patchJson("/api/admin/users/{$friend->id}/quota", [
            'storage_quota_bytes' => $quota,
        ])->assertOk()
            ->assertJsonPath('data.storage_quota_bytes', $quota);

        $this->assertSame($quota, (int) $friend->fresh()->storage_quota_bytes);
    }

    public function test_admin_can_remove_another_users_track(): void
    {
        $admin = User::factory()->admin()->create();
        $friend = User::factory()->create(['storage_used_bytes' => 1000]);
        $track = Track::factory()->for($friend, 'owner')->create(['size' => 1000]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/tracks?q='.$track->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $track->id);

        $this->deleteJson("/api/admin/tracks/{$track->id}")
            ->assertOk();

        $this->assertSoftDeleted('tracks', ['id' => $track->id]);
        $this->assertSame(0, (int) $friend->fresh()->storage_used_bytes);
    }

    public function test_non_admin_cannot_hit_admin_routes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/admin/tracks')->assertForbidden();
    }
}
