<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $mode = config('max-tune.mode');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_admin' => $user->isAdmin(),
                'status' => $user->status,
                'storage_used_bytes' => (int) $user->storage_used_bytes,
                'storage_quota_bytes' => $user->storageQuotaBytes(),
            ],
            'app' => [
                'name' => config('app.name'),
                'mode' => $mode,
                'registration_enabled' => in_array($mode, ['invite', 'public'], true),
                'max_upload_bytes' => (int) config('max-tune.max_upload_kb', 51200) * 1024,
                'upload_rate_limit' => (int) config('max-tune.upload_rate_limit', 20),
                'default_storage_quota_bytes' => (int) config('max-tune.default_storage_quota_bytes'),
            ],
        ]);
    }
}
