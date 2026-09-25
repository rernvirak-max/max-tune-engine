<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'storage_used_bytes', 'storage_quota_bytes', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'storage_used_bytes' => 'integer',
            'storage_quota_bytes' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function storageQuotaBytes(): int
    {
        if ($this->storage_quota_bytes !== null) {
            return (int) $this->storage_quota_bytes;
        }

        return (int) config('max-tune.default_storage_quota_bytes', 5 * 1024 * 1024 * 1024);
    }

    public function storageRemainingBytes(): int
    {
        return max(0, $this->storageQuotaBytes() - (int) $this->storage_used_bytes);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function createdInvites(): HasMany
    {
        return $this->hasMany(InviteCode::class, 'created_by');
    }

    public function mediaImports(): HasMany
    {
        return $this->hasMany(MediaImport::class);
    }
}
