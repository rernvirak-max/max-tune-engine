<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'vireak@maxtune.local'],
            [
                'name' => 'Vireak',
                'password' => 'password',
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
                'storage_used_bytes' => 0,
            ]
        );
    }
}
