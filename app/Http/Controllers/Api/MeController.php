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

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'storage_used_bytes' => $user->storage_used_bytes,
            ],
            'app' => [
                'name' => config('app.name'),
                'mode' => config('max-tune.mode'),
                'registration_enabled' => config('max-tune.mode') === 'public',
            ],
        ]);
    }
}
