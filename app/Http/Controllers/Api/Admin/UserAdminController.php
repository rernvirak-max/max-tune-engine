<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminUserRequest;
use App\Models\User;
use App\Services\YoutubeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->orderBy('id')
            ->get()
            ->map(fn (User $user) => $this->payload($user));

        return response()->json(['data' => $users]);
    }

    /**
     * Admin creates a regular user directly (any APP_MODE). When no password
     * is supplied a strong temporary one is generated and returned once in
     * `temporary_password`; only its hash is stored and it is never logged.
     */
    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $temporaryPassword = null;
        $password = $data['password'] ?? null;

        if ($password === null || $password === '') {
            $temporaryPassword = Str::password(16, letters: true, numbers: true, symbols: false);
            $password = $temporaryPassword;
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $password,
            'role' => 'user',
            'status' => 'active',
            'storage_used_bytes' => 0,
            'storage_quota_bytes' => $data['quota_bytes'] ?? null,
        ]);

        $body = ['data' => $this->payload($user->fresh())];

        if ($temporaryPassword !== null) {
            $body['temporary_password'] = $temporaryPassword;
        }

        return response()->json($body, 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function disable(Request $request, User $user, YoutubeImportService $imports): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot disable yourself.'], 422);
        }

        if ($user->isAdmin()) {
            return response()->json(['message' => 'Cannot disable the admin account.'], 422);
        }

        $user->status = 'disabled';
        $user->save();
        $user->tokens()->delete();
        $imports->cancelQueued($user);

        return response()->json(['data' => $this->payload($user->fresh())]);
    }

    public function enable(Request $request, User $user): JsonResponse
    {
        $user->status = 'active';
        $user->save();

        return response()->json(['data' => $this->payload($user->fresh())]);
    }

    public function updateQuota(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'storage_quota_bytes' => ['required', 'integer', 'min:0'],
        ]);

        $user->storage_quota_bytes = $data['storage_quota_bytes'];
        $user->save();

        return response()->json(['data' => $this->payload($user->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user): array
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
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
