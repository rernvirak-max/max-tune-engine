<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachPlaylistTrackRequest;
use App\Http\Requests\StorePlaylistRequest;
use App\Http\Requests\UpdatePlaylistRequest;
use App\Http\Resources\PlaylistResource;
use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlaylistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $playlists = Playlist::query()
            ->where('user_id', $request->user()->id)
            ->withCount('tracks')
            ->with(['tracks' => fn ($q) => $q->orderByPivot('position')->limit(1)])
            ->latest()
            ->get();

        return PlaylistResource::collection($playlists)->response();
    }

    public function store(StorePlaylistRequest $request): JsonResponse
    {
        $playlist = $request->user()->playlists()->create(
            $request->safe()->only(['title', 'description', 'visibility'])
        );

        $playlist->loadCount('tracks');

        return (new PlaylistResource($playlist))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Playlist $playlist): PlaylistResource
    {
        $this->authorizeOwner($request, $playlist);

        $playlist->load([
            'tracks' => fn ($q) => $q
                ->orderByPivot('position')
                ->withExists(['likes as liked' => fn ($lq) => $lq->where('user_id', $request->user()->id)]),
        ]);
        $playlist->loadCount('tracks');

        return new PlaylistResource($playlist);
    }

    public function update(UpdatePlaylistRequest $request, Playlist $playlist): PlaylistResource
    {
        $this->authorizeOwner($request, $playlist);

        $playlist->update(
            $request->safe()->only(['title', 'description', 'visibility'])
        );

        $playlist->loadCount('tracks');

        return new PlaylistResource($playlist);
    }

    public function destroy(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorizeOwner($request, $playlist);

        $playlist->delete();

        return response()->json(['message' => 'Playlist deleted']);
    }

    public function attachTrack(
        AttachPlaylistTrackRequest $request,
        Playlist $playlist,
    ): PlaylistResource {
        $this->authorizeOwner($request, $playlist);

        $trackId = (int) $request->validated('track_id');

        $track = Track::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($trackId)
            ->firstOrFail();

        if ($playlist->tracks()->where('tracks.id', $track->id)->exists()) {
            return $this->playlistWithTracks($request, $playlist);
        }

        $nextPosition = (int) $playlist->playlistTracks()->max('position') + 1;

        $playlist->tracks()->attach($track->id, ['position' => $nextPosition]);

        return $this->playlistWithTracks($request, $playlist);
    }

    public function detachTrack(
        Request $request,
        Playlist $playlist,
        Track $track,
    ): PlaylistResource {
        $this->authorizeOwner($request, $playlist);

        DB::transaction(function () use ($playlist, $track) {
            $playlist->tracks()->detach($track->id);

            $ordered = $playlist->playlistTracks()->orderBy('position')->get();
            foreach ($ordered as $index => $row) {
                $row->update(['position' => $index]);
            }
        });

        return $this->playlistWithTracks($request, $playlist);
    }

    private function playlistWithTracks(Request $request, Playlist $playlist): PlaylistResource
    {
        $playlist->load([
            'tracks' => fn ($q) => $q
                ->orderByPivot('position')
                ->withExists(['likes as liked' => fn ($lq) => $lq->where('user_id', $request->user()->id)]),
        ]);
        $playlist->loadCount('tracks');

        return new PlaylistResource($playlist);
    }

    private function authorizeOwner(Request $request, Playlist $playlist): void
    {
        if ($request->user()->id !== $playlist->user_id) {
            abort(404);
        }
    }
}
