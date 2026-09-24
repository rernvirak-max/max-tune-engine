<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportJamendoTrackRequest;
use App\Http\Resources\TrackResource;
use App\Services\JamendoClient;
use App\Services\JamendoImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CatalogController extends Controller
{
    public function searchJamendo(Request $request, JamendoClient $jamendo): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        try {
            $results = $jamendo->searchTracks(
                $validated['q'],
                (int) ($validated['limit'] ?? 20),
                (int) ($validated['offset'] ?? 0),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $importedIds = $request->user()->tracks()
            ->where('source', 'jamendo')
            ->whereIn('external_id', array_column($results, 'external_id'))
            ->pluck('external_id')
            ->all();

        $data = array_map(function (array $row) use ($importedIds) {
            $row['imported'] = in_array($row['external_id'], $importedIds, true);

            return $row;
        }, $results);

        return response()->json(['data' => $data]);
    }

    public function importJamendo(
        ImportJamendoTrackRequest $request,
        JamendoImportService $imports,
    ): JsonResponse {
        try {
            $track = $imports->importLinked(
                $request->user(),
                (string) $request->validated('external_id'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $track->setAttribute('liked', false);

        return (new TrackResource($track))
            ->response()
            ->setStatusCode(201);
    }
}
