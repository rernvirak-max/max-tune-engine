<?php

namespace App\Services;

use App\Models\Track;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioStreamer
{
    public function __construct(private MediaStorage $media) {}

    /**
     * Stream audio with HTTP Range support for seeking.
     */
    public function stream(Track $track, Request $request): BinaryFileResponse|StreamedResponse
    {
        if (! $track->storage_path || ! $this->media->exists($track->storage_path)) {
            abort(404, 'Audio file missing.');
        }

        $absolute = $this->media->absolutePath($track->storage_path);

        if ($absolute === null || ! is_file($absolute)) {
            // Fallback for non-local disks later
            return $this->media->disk()->response(
                $track->storage_path,
                basename($track->storage_path),
                [
                    'Content-Type' => $track->mime ?: 'application/octet-stream',
                    'Accept-Ranges' => 'bytes',
                    'Cache-Control' => 'private, max-age=3600',
                ]
            );
        }

        $mime = $track->mime ?: 'application/octet-stream';
        $response = new BinaryFileResponse($absolute, 200, [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            basename($track->storage_path)
        );

        // Enables Range / partial content for HTML5 audio seeking
        BinaryFileResponse::trustXSendfileTypeHeader();
        $response->prepare($request);

        return $response;
    }
}
