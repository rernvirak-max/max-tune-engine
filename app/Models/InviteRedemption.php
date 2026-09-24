<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InviteRedemption extends Model
{
    protected $fillable = [
        'invite_code_id',
        'user_id',
    ];

    public function inviteCode(): BelongsTo
    {
        return $this->belongsTo(InviteCode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
