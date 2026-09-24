<?php

/**
 * One-off smoke: create a tiny silent WAV and upload via TrackUploadService.
 */

use App\Models\User;
use App\Services\TrackUploadService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->first();
if (! $user) {
    fwrite(STDERR, "No user\n");
    exit(1);
}

// Minimal 0.1s mono 8-bit WAV
$sampleRate = 8000;
$duration = 0.1;
$numSamples = (int) ($sampleRate * $duration);
$dataSize = $numSamples;
$fileSize = 44 + $dataSize;
$wav = pack('N', 0x52494646); // RIFF - will fix
$wav = 'RIFF'.pack('V', $fileSize - 8).'WAVEfmt '.pack('V', 16)
    .pack('v', 1) // PCM
    .pack('v', 1) // mono
    .pack('V', $sampleRate)
    .pack('V', $sampleRate) // byte rate
    .pack('v', 1) // block align
    .pack('v', 8) // bits
    .'data'.pack('V', $dataSize)
    .str_repeat("\x80", $dataSize);

$tmp = tempnam(sys_get_temp_dir(), 'mt').'.wav';
file_put_contents($tmp, $wav);

$file = new UploadedFile($tmp, 'smoke-test.wav', 'audio/wav', null, true);
$track = app(TrackUploadService::class)->upload($user, $file);

@unlink($tmp);

echo json_encode([
    'id' => $track->id,
    'title' => $track->title,
    'duration_ms' => $track->duration_ms,
    'storage_path' => $track->storage_path,
    'size' => $track->size,
], JSON_PRETTY_PRINT).PHP_EOL;
