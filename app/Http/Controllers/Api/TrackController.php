<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrackRequest;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use App\Services\AudioStreamer;
use App\Services\MediaStorage;
use App\Services\TrackUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Track::query()
            ->where('user_id', $request->user()->id)
            ->withExists(['likes as liked' => fn ($q) => $q->where('user_id', $request->user()->id)])
            ->latest();

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('artist_name', 'like', "%{$search}%")
                    ->orWhere('album_name', 'like', "%{$search}%");
            });
        }

        $tracks = $query->paginate(min((int) $request->query('per_page', 50), 100));

        return TrackResource::collection($tracks)->response();
    }

    public function store(StoreTrackRequest $request, TrackUploadService $uploads): JsonResponse
    {
        $user = $request->user();
        $key = 'uploads:'.$user->id;
        $maxAttempts = (int) config('max-tune.upload_rate_limit', 20);
        $decay = (int) config('max-tune.upload_rate_decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return response()->json([
                'message' => 'Upload limit reached · Try again in an hour',
                'errors' => [
                    'file' => ['Upload limit reached · Try again in an hour'],
                ],
            ], 429);
        }

        RateLimiter::hit($key, $decay);

        $track = $uploads->upload(
            $user,
            $request->file('file'),
        );

        return (new TrackResource($track))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Track $track): TrackResource
    {
        $this->authorizeOwner($request, $track);

        $track->loadExists(['likes as liked' => fn ($q) => $q->where('user_id', $request->user()->id)]);

        return new TrackResource($track);
    }

    public function destroy(Request $request, Track $track, MediaStorage $media): JsonResponse
    {
        $this->authorizeOwner($request, $track);

        $size = (int) $track->size;
        $media->delete($track->storage_path);
        $media->delete($track->cover_path);
        $track->delete();

        if ($size > 0) {
            $user = $request->user();
            $user->storage_used_bytes = max(0, (int) $user->storage_used_bytes - $size);
            $user->save();
        }

        return response()->json(['message' => 'Track deleted']);
    }

    public function cover(Request $request, Track $track, MediaStorage $media): StreamedResponse|Response
    {
        $this->authorizeStreamAccess($request, $track);

        if (! $track->cover_path || ! $media->exists($track->cover_path)) {
            abort(404);
        }

        return $media->disk()->response($track->cover_path);
    }

    public function stream(Request $request, Track $track, AudioStreamer $streamer): BinaryFileResponse|StreamedResponse
    {
        $this->authorizeStreamAccess($request, $track);

        return $streamer->stream($track, $request);
    }

    private function authorizeOwner(Request $request, Track $track): void
    {
        if ($request->user()->id !== $track->user_id) {
            abort(403, 'You do not own this track.');
        }
    }

    private function authorizeStreamAccess(Request $request, Track $track): void
    {
        if ($request->hasValidSignature(absolute: false)) {
            return;
        }

        if ($request->user()?->id === $track->user_id) {
            return;
        }

        abort(403);
    }
}
