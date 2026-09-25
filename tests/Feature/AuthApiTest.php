<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_from_spa_origin_issues_bearer_token_without_session_cookies(): void
    {
        Config::set('sanctum.stateful', ['maxtune.example.com']);

        $user = User::factory()->create(['password' => 'password123']);

        $this->withHeaders([
            'Origin' => 'https://maxtune.example.com',
            'Referer' => 'https://maxtune.example.com/',
        ])->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertCookieMissing('XSRF-TOKEN');
    }
}
