<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Like;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $tracks = Track::query()
            ->where('user_id', $userId)
            ->whereHas('likes', fn ($q) => $q->where('user_id', $userId))
            ->withExists(['likes as liked' => fn ($q) => $q->where('user_id', $userId)])
            ->orderByDesc(
                Like::query()
                    ->select('created_at')
                    ->whereColumn('likes.track_id', 'tracks.id')
                    ->where('likes.user_id', $userId)
                    ->limit(1)
            )
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return TrackResource::collection($tracks)->response();
    }

    public function store(Request $request, Track $track): TrackResource
    {
        $this->authorizeTrackAccess($request, $track);

        Like::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'track_id' => $track->id,
        ]);

        $track->setAttribute('liked', true);

        return new TrackResource($track);
    }

    public function destroy(Request $request, Track $track): TrackResource
    {
        $this->authorizeTrackAccess($request, $track);

        Like::query()
            ->where('user_id', $request->user()->id)
            ->where('track_id', $track->id)
            ->delete();

        $track->setAttribute('liked', false);

        return new TrackResource($track);
    }

    private function authorizeTrackAccess(Request $request, Track $track): void
    {
        if ($request->user()->id !== $track->user_id) {
            abort(404);
        }
    }
}
