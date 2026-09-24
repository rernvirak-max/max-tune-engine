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
        $maxKb = (int) config('max-tune.max_upload_kb', 51200); // 50 MB

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
            'file.max' => 'File too large · Max 50 MB',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->file('file') && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            abort(response()->json([
                'message' => 'File too large · Max 50 MB',
            ], 413));
        }
    }
}
