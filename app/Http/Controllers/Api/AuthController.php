<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InviteRedeemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['This account is disabled'],
            ]);
        }

        $deviceName = $credentials['device_name'] ?? 'max-tune-web';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ]);
    }

    public function register(Request $request, InviteRedeemService $invites): JsonResponse
    {
        $mode = config('max-tune.mode', 'personal');

        if ($mode === 'personal') {
            return response()->json([
                'message' => 'Registration is closed',
            ], 403);
        }

        if ($mode === 'invite') {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'invite_code' => ['required', 'string', 'max:64'],
                'device_name' => ['sometimes', 'string', 'max:120'],
            ], [
                'invite_code.required' => 'Invite code required',
                'email.unique' => 'Email already registered · Sign in instead',
            ]);

            $user = $invites->redeemAndRegister($data['invite_code'], [
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $token = $user->createToken($data['device_name'] ?? 'max-tune-web')->plainTextToken;

            return response()->json([
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $this->userPayload($user),
            ], 201);
        }

        // public mode
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'user',
            'status' => 'active',
        ]);

        $token = $user->createToken($data['device_name'] ?? 'max-tune-web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_admin' => $user->isAdmin(),
            'status' => $user->status,
            'storage_used_bytes' => (int) $user->storage_used_bytes,
            'storage_quota_bytes' => $user->storageQuotaBytes(),
        ];
    }
}
