<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\YoutubeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
