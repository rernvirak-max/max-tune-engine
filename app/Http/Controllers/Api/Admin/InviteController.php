<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\InviteCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InviteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invites = InviteCode::query()
            ->latest()
            ->get()
            ->map(fn (InviteCode $invite) => $this->payload($invite));

        return response()->json(['data' => $invites]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'max_uses' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'code' => ['nullable', 'string', 'min:4', 'max:64', 'alpha_dash', Rule::unique('invite_codes', 'code')],
            'generate' => ['sometimes', 'boolean'],
        ]);

        $generate = $data['generate'] ?? empty($data['code']);
        $code = $generate
            ? strtoupper(Str::random(10))
            : strtoupper((string) $data['code']);

        if ($generate) {
            while (InviteCode::query()->where('code', $code)->exists()) {
                $code = strtoupper(Str::random(10));
            }
        }

        $invite = InviteCode::query()->create([
            'code' => $code,
            'label' => $data['label'] ?? null,
            'max_uses' => $data['max_uses'] ?? 1,
            'uses_count' => 0,
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($invite)], 201);
    }

    public function revoke(Request $request, InviteCode $invite): JsonResponse
    {
        $invite->is_active = false;
        $invite->save();

        return response()->json(['data' => $this->payload($invite->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(InviteCode $invite): array
    {
        return [
            'id' => $invite->id,
            'code' => $invite->code,
            'label' => $invite->label,
            'max_uses' => $invite->max_uses,
            'uses_count' => $invite->uses_count,
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'is_active' => $invite->is_active,
            'status' => $invite->status(),
            'created_at' => $invite->created_at?->toIso8601String(),
            'register_path' => '/register?code='.$invite->code,
        ];
    }
}
