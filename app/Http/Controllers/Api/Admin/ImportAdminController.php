<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminMediaImportResource;
use App\Models\MediaImport;
use App\Services\TrackRemover;
use App\Services\YoutubeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ImportAdminController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $imports = MediaImport::query()
            ->with('owner:id,email,name')
            ->latest()
            ->latest('id')
            ->limit((int) config('max-tune.youtube.list_limit'))
            ->get();

        return AdminMediaImportResource::collection($imports);
    }

    /**
     * Ready imports also lose their track and files (same removal as Admin → Tracks),
     * in one transaction with the import row.
     */
    public function destroy(
        Request $request,
        MediaImport $mediaImport,
        YoutubeImportService $imports,
        TrackRemover $remover,
    ): JsonResponse {
        DB::transaction(function () use ($mediaImport, $imports, $remover) {
            $track = $imports->discard($mediaImport);

            if ($track) {
                $remover->remove($track);
            }
        });

        return response()->json(['message' => 'Import removed']);
    }
}
