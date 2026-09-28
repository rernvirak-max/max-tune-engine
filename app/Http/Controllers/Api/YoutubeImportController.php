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
        $imports = YoutubeImport::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(20)
            ->get();

        return YoutubeImportResource::collection($imports);
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

    public function destroy(Request $request, YoutubeImport $youtubeImport): JsonResponse
    {
        if ($youtubeImport->user_id !== $request->user()->id) {
            abort(404);
        }

        if ($youtubeImport->isCancellable()) {
            $youtubeImport->update([
                'status' => YoutubeImport::STATUS_CANCELLED,
                'status_message' => 'Cancelled',
                'error_message' => null,
            ]);
        } else {
            $youtubeImport->delete();
        }

        return response()->json(['data' => ['ok' => true]]);
    }
}
