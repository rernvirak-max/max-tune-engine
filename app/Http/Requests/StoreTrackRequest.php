<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $extensions = config('max-tune.allowed_audio_extensions', ['mp3', 'm4a', 'flac', 'wav']);
        $maxKb = (int) config('max-tune.max_upload_kb', 102400); // 100 MB

        return [
            'file' => [
                'required',
                File::types($extensions)
                    ->max($maxKb),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose an audio file to upload (or the file exceeded the server upload limit).',
            'file.max' => 'That file is too large (max ~100 MB).',
        ];
    }

    protected function prepareForValidation(): void
    {
        // When PHP rejects an oversized body, $_FILES is empty — surface a clear error.
        if (! $this->file('file') && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            abort(response()->json([
                'message' => 'File too large for the current PHP upload limit. Raise upload_max_filesize / post_max_size (e.g. 128M) and retry.',
            ], 413));
        }
    }
}
