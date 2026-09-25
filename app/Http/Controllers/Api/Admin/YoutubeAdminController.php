<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateYoutubeCookiesRequest;
use App\Services\YoutubeCookieStore;
use App\Services\YtDlp;
use Illuminate\Http\JsonResponse;

class YoutubeAdminController extends Controller
{
    public function status(YtDlp $ytDlp, YoutubeCookieStore $cookies): JsonResponse
    {
        return response()->json(['data' => [
            'yt_dlp_version' => $ytDlp->version(),
            'cookies_set' => $cookies->isSet(),
        ]]);
    }

    /**
     * Replace the cookies file. The contents are never echoed back.
     */
    public function updateCookies(UpdateYoutubeCookiesRequest $request, YoutubeCookieStore $cookies): JsonResponse
    {
        $cookies->store((string) $request->validated('cookies'));

        return response()->json(['data' => ['cookies_set' => true]]);
    }
}
