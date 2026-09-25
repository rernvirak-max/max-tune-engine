<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $email = trim((string) $request->query('email', ''));

        $query = Track::query()->with('owner:id,email,name')->latest();

        if ($q !== '') {
            if (ctype_digit($q)) {
                $query->where(function ($builder) use ($q) {
                    $builder->where('id', (int) $q)
                        ->orWhere('title', 'like', "%{$q}%");
                });
            } else {
                $query->where('title', 'like', "%{$q}%");
            }
        }

        if ($email !== '') {
            $query->whereHas('owner', fn ($b) => $b->where('email', 'like', "%{$email}%"));
        }

        $tracks = $query->limit(50)->get()->map(function (Track $track) {
            return [
                'id' => $track->id,
                'title' => $track->title,
                'artist_name' => $track->artist_name,
                'size' => (int) $track->size,
                'owner' => [
                    'id' => $track->owner?->id,
                    'email' => $track->owner?->email,
                    'name' => $track->owner?->name,
                ],
            ];
        });

        return response()->json(['data' => $tracks]);
    }

    public function destroy(Request $request, Track $track, MediaStorage $media): JsonResponse
    {
        $owner = $track->owner;
        $size = (int) $track->size;

        $media->delete($track->storage_path);
        $media->delete($track->cover_path);
        $track->delete();

        if ($owner && $size > 0) {
            $owner->storage_used_bytes = max(0, (int) $owner->storage_used_bytes - $size);
            $owner->save();
        }

        return response()->json(['message' => 'Track removed']);
    }
}
