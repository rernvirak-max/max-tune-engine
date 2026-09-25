<?php

namespace App\Services;

use App\Models\InviteCode;
use App\Models\InviteRedemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InviteRedeemService
{
    /**
     * Atomically redeem an invite code and create the user.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function redeemAndRegister(string $rawCode, array $data): User
    {
        $code = strtoupper(trim($rawCode));

        return DB::transaction(function () use ($code, $data) {
            /** @var InviteCode|null $invite */
            $invite = InviteCode::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if (! $invite) {
                throw ValidationException::withMessages([
                    'invite_code' => ["That invite doesn't work"],
                ]);
            }

            $reason = $invite->redeemFailureReason();

            if ($reason === 'expired') {
                throw ValidationException::withMessages([
                    'invite_code' => ['This invite has expired'],
                ]);
            }

            if ($reason === 'exhausted') {
                throw ValidationException::withMessages([
                    'invite_code' => ['This invite has no uses left'],
                ]);
            }

            if ($reason !== null) {
                throw ValidationException::withMessages([
                    'invite_code' => ["That invite doesn't work"],
                ]);
            }

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'user',
                'status' => 'active',
                'storage_used_bytes' => 0,
                'storage_quota_bytes' => null,
            ]);

            InviteRedemption::query()->create([
                'invite_code_id' => $invite->id,
                'user_id' => $user->id,
            ]);

            $invite->increment('uses_count');

            return $user->fresh();
        });
    }
}