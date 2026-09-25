<?php

namespace App\Models;

use Database\Factories\InviteCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InviteCode extends Model
{
    /** @use HasFactory<InviteCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'label',
        'max_uses',
        'uses_count',
        'expires_at',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(InviteRedemption::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->uses_count >= $this->max_uses;
    }

    public function status(): string
    {
        if (! $this->is_active) {
            return 'revoked';
        }
        if ($this->isExpired()) {
            return 'expired';
        }
        if ($this->isExhausted()) {
            return 'exhausted';
        }

        return 'active';
    }

    public function redeemFailureReason(): ?string
    {
        if (! $this->is_active) {
            return 'revoked';
        }
        if ($this->isExpired()) {
            return 'expired';
        }
        if ($this->isExhausted()) {
            return 'exhausted';
        }

        return null;
    }
}
