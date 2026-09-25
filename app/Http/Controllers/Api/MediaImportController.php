<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreYoutubeImportRequest;
use App\Http\Resources\MediaImportResource;
use App\Models\MediaImport;
use App\Services\MediaStorage;
use App\Services\YoutubeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaImportController extends Controller
{
    private const STATUS_FILTERS = [
        'active' => MediaImport::ACTIVE_STATUSES,
        'failed' => [MediaImport::STATUS_FAILED],
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', 'in:active,failed,all'],
        ]);

        $statuses = self::STATUS_FILTERS[$request->query('status', 'all')] ?? null;

        $imports = $request->user()->mediaImports()
            ->when($statuses, fn ($query) => $query->whereIn('status', $statuses))
            ->latest()
            ->latest('id')
            ->limit((int) config('max-tune.youtube.list_limit'))
            ->get();

        return MediaImportResource::collection($imports);
    }

    public function storeYoutube(StoreYoutubeImportRequest $request, YoutubeImportService $imports): JsonResponse
    {
        $import = $imports->submit($request->user(), (string) $request->validated('url'));

        return (new MediaImportResource($import))
            ->response()
            ->setStatusCode(202);
    }

    public function show(Request $request, MediaImport $mediaImport): MediaImportResource
    {
        $this->authorizeOwner($request, $mediaImport);

        return new MediaImportResource($mediaImport);
    }

    public function retry(Request $request, MediaImport $mediaImport, YoutubeImportService $imports): JsonResponse
    {
        $this->authorizeOwner($request, $mediaImport);

        return (new MediaImportResource($imports->retry($mediaImport)))
            ->response()
            ->setStatusCode(202);
    }

    public function destroy(Request $request, MediaImport $mediaImport, YoutubeImportService $imports): JsonResponse
    {
        $this->authorizeOwner($request, $mediaImport);

        $imports->discard($mediaImport);

        return response()->json(['message' => 'Import dismissed']);
    }

    public function thumbnail(Request $request, MediaImport $mediaImport, MediaStorage $media): StreamedResponse|Response
    {
        $this->authorizeSignedOrOwner($request, $mediaImport->user_id);

        if (! $media->exists($mediaImport->thumbnail_path)) {
            abort(404);
        }

        return $media->disk()->response($mediaImport->thumbnail_path);
    }

    /**
     * Someone else's import is indistinguishable from a missing one.
     */
    private function authorizeOwner(Request $request, MediaImport $mediaImport): void
    {
        if ($request->user()->id !== $mediaImport->user_id) {
            abort(404);
        }
    }
}
