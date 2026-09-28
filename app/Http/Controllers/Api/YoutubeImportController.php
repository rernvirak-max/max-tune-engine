<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreYoutubeImportRequest;
use App\Http\Resources\YoutubeImportResource;
use App\Jobs\ProcessYoutubeImportJob;
use App\Models\YoutubeImport;
use App\Services\YoutubeDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class YoutubeImportController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = (string) $request->query('status', 'all');

        $query = YoutubeImport::query()
            ->where('user_id', $request->user()->id)
            ->whereNotIn('status', [YoutubeImport::STATUS_CANCELLED])
            ->latest();

        if ($status === 'active') {
            $query->whereIn('status', [
                YoutubeImport::STATUS_QUEUED,
                YoutubeImport::STATUS_WAITING_METADATA,
                YoutubeImport::STATUS_DOWNLOADING,
                YoutubeImport::STATUS_PROCESSING,
            ]);
        } elseif ($status === 'failed') {
            $query->where('status', YoutubeImport::STATUS_FAILED);
        }

        return YoutubeImportResource::collection($query->limit(50)->get());
    }

    public function store(
        StoreYoutubeImportRequest $request,
        YoutubeDownloadService $youtube,
    ): JsonResponse {
        $url = (string) $request->validated('url');

        $import = YoutubeImport::query()->create([
            'user_id' => $request->user()->id,
            'url' => $url,
            'video_id' => $youtube->extractVideoId($url),
            'status' => YoutubeImport::STATUS_QUEUED,
            'status_message' => 'Queued',
            'progress' => 0,
        ]);

        ProcessYoutubeImportJob::dispatch($import->id);

        return (new YoutubeImportResource($import))
            ->response()
            ->setStatusCode(201);
    }

    public function retry(Request $request, YoutubeImport $import): JsonResponse
    {
        if ($import->user_id !== $request->user()->id) {
            abort(404);
        }

        if ($import->status !== YoutubeImport::STATUS_FAILED) {
            return response()->json([
                'message' => 'Only failed imports can be retried.',
            ], 422);
        }

        $import->update([
            'status' => YoutubeImport::STATUS_QUEUED,
            'status_message' => 'Queued',
            'error_message' => null,
            'progress' => 0,
            'track_id' => null,
        ]);

        ProcessYoutubeImportJob::dispatch($import->id);

        return (new YoutubeImportResource($import->fresh()))->response();
    }

    public function destroy(Request $request, YoutubeImport $import): JsonResponse
    {
        if ($import->user_id !== $request->user()->id) {
            abort(404);
        }

        if ($import->isCancellable()) {
            $import->update([
                'status' => YoutubeImport::STATUS_CANCELLED,
                'status_message' => 'Cancelled',
                'error_message' => null,
            ]);
        } else {
            $import->delete();
        }

        return response()->json(['data' => ['ok' => true]]);
    }
}
