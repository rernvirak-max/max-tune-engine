<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    public function show(): JsonResponse
    {
        $mode = config('max-tune.mode');

        return response()->json([
            'app' => [
                'name' => config('app.name'),
                'mode' => $mode,
                'registration_enabled' => in_array($mode, ['invite', 'public'], true),
            ],
        ]);
    }
}