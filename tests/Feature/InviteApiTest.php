<?php

namespace Tests\Feature;

use App\Models\InviteCode;
use App\Models\InviteRedemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InviteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('max-tune.mode', 'invite');
    }

    public function test_register_requires_invite_code(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Friend',
            'email' => 'friend@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['invite_code']);
    }

    public function test_register_rejects_invalid_invite(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Friend',
            'email' => 'friend@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => 'NOPE',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.invite_code.0', "That invite doesn't work");
    }

    public function test_register_redeems_valid_invite_atomically(): void
    {
        $admin = User::factory()->admin()->create();
        $invite = InviteCode::factory()->create([
            'created_by' => $admin->id,
            'code' => 'FRIEND01',
            'max_uses' => 1,
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Friend',
            'email' => 'friend@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => 'friend01',
        ])->assertCreated()
            ->assertJsonPath('user.email', 'friend@example.com')
            ->assertJsonPath('user.is_admin', false);

        $this->assertDatabaseHas('invite_redemptions', [
            'invite_code_id' => $invite->id,
        ]);
        $this->assertSame(1, $invite->fresh()->uses_count);

        // Second redeem fails (exhausted)
        $this->postJson('/api/auth/register', [
            'name' => 'Other',
            'email' => 'other@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => 'FRIEND01',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.invite_code.0', 'This invite has no uses left');
    }

    public function test_expired_and_revoked_invites_fail_distinctly(): void
    {
        $admin = User::factory()->admin()->create();

        InviteCode::factory()->expired()->create([
            'created_by' => $admin->id,
            'code' => 'EXPIRED1',
        ]);
        InviteCode::factory()->revoked()->create([
            'created_by' => $admin->id,
            'code' => 'REVOKED1',
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'A',
            'email' => 'a@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => 'EXPIRED1',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.invite_code.0', 'This invite has expired');

        $this->postJson('/api/auth/register', [
            'name' => 'B',
            'email' => 'b@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => 'REVOKED1',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.invite_code.0', "That invite doesn't work");
    }

    public function test_non_admin_cannot_manage_invites(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/invites')->assertForbidden();
        $this->postJson('/api/admin/invites', ['label' => 'x'])->assertForbidden();
    }

    public function test_admin_can_create_list_and_revoke_invites(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/invites', [
            'label' => 'Family',
            'max_uses' => 2,
            'generate' => true,
        ])->assertCreated()
            ->assertJsonPath('data.label', 'Family')
            ->assertJsonPath('data.max_uses', 2)
            ->assertJsonPath('data.status', 'active');

        $this->getJson('/api/admin/invites')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $id = InviteCode::query()->first()->id;

        $this->postJson("/api/admin/invites/{$id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');
    }

    public function test_personal_mode_blocks_register(): void
    {
        Config::set('max-tune.mode', 'personal');

        $this->postJson('/api/auth/register', [
            'name' => 'X',
            'email' => 'x@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => 'ANY',
        ])->assertForbidden();
    }
}
